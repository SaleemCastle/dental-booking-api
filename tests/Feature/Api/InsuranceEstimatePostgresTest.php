<?php

namespace Tests\Feature\Api;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InsuranceEstimateWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsuranceEstimatePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName(), 'Insurance estimate API must be exercised on PostgreSQL.');
    }

    private function actor(array $permissions = ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'insurance_estimates.view', 'insurance_estimates.create']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'estimate-'.$user->id, 'name' => 'Synthetic estimate role']);
        foreach ($permissions as $slug) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        return $user;
    }

    private function association(Patient $patient): int
    {
        return $this->postJson('/api/patients/'.$patient->id.'/insurance-associations', ['unverified_insurer_name' => 'Synthetic provider'])
            ->assertCreated()->json('data.association.id');
    }

    private function rule(int $association, array $overrides = []): int
    {
        $input = array_replace(['source' => 'staff_observation', 'scope' => 'all', 'coverage' => 'covered', 'benefit_type' => 'percentage',
            'percentage' => '80.00', 'observed_at' => '2020-01-02T10:00:00Z', 'provenance' => 'Synthetic staff source', 'reason' => 'Synthetic observation'], $overrides);
        return $this->postJson('/api/patients/'.$this->patientId.'/insurance-associations/'.$association.'/benefits', $input)
            ->assertCreated()->json('data.benefit.id');
    }

    private int $patientId;

    private function estimatePayload(array $overrides = []): array
    {
        return array_replace(['association_id' => $this->associationId, 'benefit_rule_id' => $this->ruleId,
            'service_date' => '2030-04-01', 'treatment_description' => 'Synthetic treatment',
            'gross_amount' => '100.01', 'currency' => 'JMD'], $overrides);
    }

    private int $associationId;
    private int $ruleId;

    private function setupPatient(): Patient
    {
        $patient = Patient::factory()->create();
        $this->patientId = $patient->id;
        $this->associationId = $this->association($patient);
        return $patient;
    }

    public function test_percentage_and_procedure_limit_use_minor_units_and_do_not_touch_billing(): void
    {
        $actor = $this->actor();
        $patient = $this->setupPatient();
        $this->ruleId = $this->rule($this->associationId);
        $counts = collect(['invoices', 'invoice_items', 'payments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload())
            ->assertCreated()->assertJsonPath('data.estimate.status', 'resolved')
            ->assertJsonPath('data.estimate.insurer_amount', '80.01')->assertJsonPath('data.estimate.patient_amount', '20.00')
            ->assertJsonPath('data.estimate.calculation.calculator_version', 'p4-m3-a.1');
        $roundingPatient = $this->setupPatient();
        $roundingRule = $this->rule($this->associationId, ['percentage' => '50.00']);
        $this->postJson('/api/patients/'.$roundingPatient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $roundingRule, 'gross_amount' => '0.01']))
            ->assertCreated()->assertJsonPath('data.estimate.insurer_amount', '0.01')->assertJsonPath('data.estimate.patient_amount', '0.00');
        $patient = $this->setupPatient();
        $limitRule = $this->rule($this->associationId, ['scope' => 'procedure', 'procedure_code' => 'SYN-4', 'code_system' => 'Synthetic',
            'benefit_type' => 'fixed', 'percentage' => null, 'amount' => '40.00', 'currency' => 'JMD', 'procedure_limit' => '25.00']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $limitRule,
            'procedure_code' => 'SYN-4', 'code_system' => 'Synthetic', 'gross_amount' => '100.01']))
            ->assertCreated()->assertJsonPath('data.estimate.insurer_amount', '25.00')->assertJsonPath('data.estimate.patient_amount', '75.01');
        foreach ($counts as $table => $count) $this->assertSame($count, DB::table($table)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'estimate_created', 'actor_id' => $actor->id]);
    }

    public function test_fixed_amount_exclusion_unknown_conflict_and_uninsured_paths_are_explicit(): void
    {
        $this->actor();
        $patient = $this->setupPatient();
        $fixed = $this->rule($this->associationId, ['benefit_type' => 'fixed', 'percentage' => null, 'amount' => '35.25', 'currency' => 'JMD']);
        $this->ruleId = $fixed;
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $fixed]))
            ->assertCreated()->assertJsonPath('data.estimate.insurer_amount', '35.25')->assertJsonPath('data.estimate.patient_amount', '64.76');
        $patient = $this->setupPatient();
        $excluded = $this->rule($this->associationId, ['coverage' => 'excluded', 'benefit_type' => 'unknown', 'percentage' => null]);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $excluded]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'excluded')->assertJsonPath('data.estimate.insurer_amount', '0.00')
            ->assertJsonPath('data.estimate.patient_amount', '100.01')->assertJsonPath('data.estimate.calculation.reason_codes.0', 'PROCEDURE_EXCLUDED');
        $patient = $this->setupPatient();
        $unknown = $this->rule($this->associationId, ['coverage' => 'unknown', 'benefit_type' => 'unknown', 'percentage' => null]);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $unknown]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')->assertJsonPath('data.estimate.insurer_amount', null)
            ->assertJsonPath('data.estimate.calculation.reason_codes.0', 'BENEFIT_UNKNOWN');
        $patient = $this->setupPatient();
        $one = $this->rule($this->associationId);
        $this->rule($this->associationId, ['percentage' => '55.00']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $one]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')
            ->assertJsonPath('data.estimate.calculation.reason_codes.0', 'CONFLICTING_BENEFIT_OBSERVATIONS');
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['association_id' => null, 'benefit_rule_id' => null]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')
            ->assertJsonPath('data.estimate.calculation.reason_codes.0', 'BENEFIT_NOT_SELECTED');
        $patient = $this->setupPatient();
        $mismatchRule = $this->rule($this->associationId, ['currency' => 'JMD']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $mismatchRule, 'currency' => 'USD']))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')->assertJsonPath('data.estimate.calculation.reason_codes.0', 'CURRENCY_MISMATCH');
        $patient = $this->setupPatient();
        $limited = $this->rule($this->associationId, ['annual_limit' => '1000.00', 'currency' => 'JMD']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $limited]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')
            ->assertJsonPath('data.estimate.calculation.reason_codes.0', 'LIMIT_USAGE_UNKNOWN');
        $patient = $this->setupPatient();
        $visitLimited = $this->rule($this->associationId, ['visit_limit' => '100.00', 'currency' => 'JMD']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload(['benefit_rule_id' => $visitLimited]))
            ->assertCreated()->assertJsonPath('data.estimate.status', 'unresolved')
            ->assertJsonPath('data.estimate.calculation.reason_codes.0', 'LIMIT_USAGE_UNKNOWN');
    }

    public function test_snapshots_are_immutable_supersession_is_append_only_and_permissions_are_exact(): void
    {
        $this->actor();
        $patient = $this->setupPatient();
        $this->ruleId = $this->rule($this->associationId);
        $first = $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload())->assertCreated()->json('data.estimate.id');
        $url = '/api/patients/'.$patient->id.'/insurance-estimates/'.$first;
        $this->postJson($url.'/supersede', $this->estimatePayload(['gross_amount' => '125.00']))->assertCreated()
            ->assertJsonPath('data.estimate.supersedes_id', $first)->assertJsonPath('data.estimate.gross_amount', '125.00');
        $this->getJson('/api/patients/'.$patient->id.'/insurance-estimates?per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $migration = require database_path('migrations/2026_11_16_000002_create_insurance_estimates.php');
        try {
            $migration->down();
            $this->fail('Populated estimate history must prohibit migration rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('populated rollback is prohibited', $error->getMessage());
        }
        $otherPatient = Patient::factory()->create();
        $this->getJson('/api/patients/'.$otherPatient->id.'/insurance-estimates/'.$first)->assertNotFound();
        try {
            DB::transaction(fn () => DB::table('insurance_estimates')->where('id', $first)->update(['gross_amount' => '1.00']));
            $this->fail('Estimate history must be immutable.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('insurance_estimates', ['id' => $first, 'gross_amount' => '100.01']);
        }
        $this->actor(['patients.view']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload())->assertForbidden();
    }

    public function test_cross_tenant_route_and_direct_service_authorization_are_enforced(): void
    {
        $actor = $this->actor();
        $patient = $this->setupPatient();
        $this->ruleId = $this->rule($this->associationId);
        $estimateId = $this->postJson('/api/patients/'.$patient->id.'/insurance-estimates', $this->estimatePayload())->assertCreated()->json('data.estimate.id');

        $tenant = Tenant::factory()->create();
        $actor->tenants()->attach($tenant->id);
        $foreign = app(TenantContext::class)->run($tenant, function () use ($actor) {
            $role = Role::create(['slug' => 'estimate-foreign-'.$actor->id, 'name' => 'Foreign estimate reader']);
            foreach (['patients.view', 'insurance_estimates.view'] as $slug) {
                $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
            }
            $actor->roles()->attach($role);
            return Patient::factory()->create();
        });
        $this->getJson('/api/patients/'.$foreign->id.'/insurance-estimates/'.$estimateId, ['X-Tenant-Id' => (string) $tenant->id])->assertNotFound();

        $restricted = User::factory()->create();
        $permission = Permission::firstOrCreate(['slug' => 'patients.view'], ['name' => 'View patients']);
        $role = Role::create(['slug' => 'estimate-direct-'.$restricted->id, 'name' => 'Estimate direct test']);
        $role->permissions()->attach($permission);
        $restricted->roles()->attach($role);
        $before = DB::table('insurance_estimates')->count();
        try {
            app(TenantContext::class)->run(Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole(),
                fn () => app(InsuranceEstimateWorkflow::class)->create($patient, $this->estimatePayload(), $restricted));
            $this->fail('Direct service calls must enforce estimate creation permission.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame($before, DB::table('insurance_estimates')->count());
    }
}
