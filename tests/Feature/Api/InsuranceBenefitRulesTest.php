<?php

namespace Tests\Feature\Api;

use App\Models\InsurancePlanVersion;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientInsurerAssociation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceBenefitWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InsuranceBenefitRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
    }

    private function actor(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'benefits-'.$user->id, 'name' => 'Synthetic benefits role']);
        foreach ($permissions ?? ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'patient_insurance.reveal', 'insurance_catalog.view', 'insurance_catalog.manage'] as $slug) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function patientScope(): array
    {
        $patient = Patient::factory()->create();
        $id = $this->postJson('/api/patients/'.$patient->id.'/insurance-associations', ['unverified_insurer_name' => 'Synthetic insurer'])->assertCreated()->json('data.association.id');

        return [$patient, $id, '/api/patients/'.$patient->id.'/insurance-associations/'.$id.'/benefits'];
    }

    private function planScope(): array
    {
        $id = $this->postJson('/api/insurance/insurers', ['code' => 'SYNTHETIC', 'name' => 'Synthetic insurer'])->assertCreated()->json('data.insurer.id');
        $plan = $this->postJson('/api/insurance/insurers/'.$id.'/plans', ['code' => 'TEST', 'name' => 'Synthetic plan'])->assertCreated()->json('data.plan.id');
        $version = $this->postJson('/api/insurance/plans/'.$plan.'/versions', ['name' => 'Synthetic version', 'currency' => 'JMD', 'effective_from' => '2030-01-01'])->assertCreated()->json('data.plan_version.id');

        return [$version, '/api/insurance/plan-versions/'.$version.'/benefits'];
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'source' => 'staff_observation', 'scope' => 'category', 'category' => 'Synthetic preventive',
            'coverage' => 'covered', 'benefit_type' => 'percentage', 'percentage' => '75.25',
            'observed_at' => '2020-01-02T10:00:00-05:00', 'provenance' => 'Synthetic report containing private detail',
            'reference' => 'PRIVATE-REFERENCE', 'reason' => 'Synthetic initial observation',
            'service_from' => '2030-01-01', 'service_to' => '2030-12-31',
        ], $overrides);
    }

    private function rejectSql(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('Expected database rejection.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_provider_only_observation_and_fixed_correction_preserve_history_and_private_provenance(): void
    {
        $actor = $this->actor();
        [, , $url] = $this->patientScope();
        $first = $this->postJson($url, $this->input())->assertCreated()->assertJsonPath('data.benefit.percentage', '75.25')
            ->assertJsonPath('data.benefit.confirmation', 'unconfirmed')->assertJsonPath('data.benefit.policy_revision_id', null)
            ->assertJsonPath('data.benefit.observed_at', '2020-01-02T15:00:00.000000Z')->assertJsonPath('data.benefit.actor_id', $actor->id)
            ->assertJsonMissingPath('data.benefit.provenance')->assertJsonMissingPath('data.benefit.reason')
            ->assertHeader('Cache-Control', 'no-store, private')->json('data.benefit.id');
        $fixed = $this->input(['source' => 'patient_reported', 'benefit_type' => 'fixed', 'percentage' => null, 'amount' => '1234.50', 'currency' => 'JMD', 'supersedes_id' => $first, 'reason' => 'Correct mistaken percentage']);
        $this->postJson($url, $fixed)->assertCreated()->assertJsonPath('data.benefit.amount', '1234.50')->assertJsonPath('data.benefit.revision', 2);
        $this->postJson($url, $fixed)->assertConflict();
        $this->getJson($url.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 2);
        $this->getJson($url.'/'.$first.'/details')->assertOk()->assertJsonPath('data.details.reference', 'PRIVATE-REFERENCE');
        $row = DB::table('insurance_benefit_rules')->find($first);
        $this->assertStringNotContainsString('private detail', $row->provenance);
        $this->assertStringNotContainsString('PRIVATE-REFERENCE', $row->reference);
        $this->assertSame('75.25', $row->percentage);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('insurance_plan_versions', 0);
        foreach (['benefit_recorded', 'benefit_corrected', 'benefit_details_revealed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'actor_id' => $actor->id]);
        }
        $this->assertStringNotContainsString('PRIVATE-REFERENCE', DB::table('audit_logs')->get()->toJson());
    }

    public function test_exclusions_limits_waiting_and_unknown_values_are_explicit_and_not_calculated(): void
    {
        $this->actor();
        [, , $url] = $this->patientScope();
        $procedure = $this->input(['scope' => 'procedure', 'category' => null, 'procedure_code' => 'SYN-001', 'code_system' => 'Synthetic only',
            'currency' => 'JMD', 'annual_limit' => '20000.00', 'visit_limit' => '5000.00', 'procedure_limit' => '2000.00',
            'waiting_period_days' => 90, 'prior_authorization_required' => true]);
        $this->postJson($url, $procedure)->assertCreated()->assertJsonPath('data.benefit.annual_limit', '20000.00')
            ->assertJsonPath('data.benefit.visit_limit', '5000.00')->assertJsonPath('data.benefit.procedure_limit', '2000.00')
            ->assertJsonPath('data.benefit.waiting_period_days', 90)->assertJsonPath('data.benefit.prior_authorization_required', true);
        foreach (['excluded', 'unknown', 'unspecified'] as $coverage) {
            $this->postJson($url, $this->input(['coverage' => $coverage, 'benefit_type' => 'unknown', 'percentage' => null]))
                ->assertCreated()->assertJsonPath('data.benefit.coverage', $coverage)->assertJsonPath('data.benefit.amount', null)
                ->assertJsonPath('data.benefit.annual_limit', null)->assertJsonPath('data.benefit.prior_authorization_required', null);
        }
        $this->postJson($url, $this->input(['scope' => 'all', 'category' => null, 'benefit_type' => 'unspecified', 'percentage' => null]))->assertCreated();
    }

    public function test_invalid_contradictory_and_financial_fields_are_rejected(): void
    {
        $this->actor();
        [, , $url] = $this->patientScope();
        foreach ([
            ['percentage' => '100.01'], ['percentage' => '-1'], ['percentage' => '1.001'], ['amount' => '5.00'],
            ['coverage' => 'excluded'], ['scope' => 'procedure'], ['procedure_limit' => '5.00', 'currency' => 'JMD'],
            ['visit_limit' => '5.00'], ['annual_limit' => '-5.00', 'currency' => 'JMD'], ['waiting_period_days' => -1],
            ['service_to' => '2029-12-31'], ['source' => 'insurer_confirmed'], ['confirmed' => true], ['payment' => '100.00'],
            ['plan_version_id' => 1], ['evidence_id' => 1], ['observed_at' => '2020-01-01'], ['reason' => ' '],
        ] as $invalid) {
            $this->postJson($url, $this->input($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('insurance_benefit_rules', 0);
    }

    public function test_plan_rules_increment_version_and_track_all_editors_before_immutable_publication(): void
    {
        $creator = $this->actor();
        [$id, $url] = $this->planScope();
        $editor = $this->actor(['insurance_catalog.view', 'insurance_catalog.manage', 'insurance_catalog.review', 'insurance_catalog.publish']);
        $input = $this->input(['source' => 'insurer_plan', 'expected_version' => 1]);
        $rule = $this->postJson($url, $input)->assertCreated()->json('data.benefit.id');
        $this->assertDatabaseHas('insurance_version_editors', ['insurance_plan_version_id' => $id, 'user_id' => $editor->id]);
        $this->assertDatabaseHas('insurance_version_editors', ['insurance_plan_version_id' => $id, 'user_id' => $creator->id]);
        $this->assertDatabaseHas('insurance_plan_versions', ['id' => $id, 'lock_version' => 2]);
        $this->postJson($url, $input)->assertConflict();
        $this->postJson('/api/insurance/plan-versions/'.$id.'/review', ['expected_version' => 2])->assertForbidden();
        $this->actor(['insurance_catalog.view', 'insurance_catalog.review', 'insurance_catalog.publish']);
        $this->postJson('/api/insurance/plan-versions/'.$id.'/review', ['expected_version' => 2])->assertOk();
        Sanctum::actingAs($editor);
        $this->postJson($url, [...$input, 'expected_version' => 3])->assertConflict();
        $this->postJson('/api/insurance/plan-versions/'.$id.'/publish', ['expected_version' => 3])->assertForbidden();
        $this->actor(['insurance_catalog.view', 'insurance_catalog.publish']);
        $this->postJson('/api/insurance/plan-versions/'.$id.'/publish', ['expected_version' => 3])->assertOk();
        Sanctum::actingAs($editor);
        $this->postJson($url, [...$input, 'expected_version' => 4, 'supersedes_id' => $rule])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $raw = (array) DB::table('insurance_benefit_rules')->find($rule);
        unset($raw['id']);
        $raw['supersedes_id'] = $rule;
        $raw['revision'] = 2;
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->insert($raw));
    }

    public function test_database_history_lineage_ownership_and_actor_guards(): void
    {
        $this->actor();
        [, , $url] = $this->patientScope();
        $id = $this->postJson($url, $this->input())->assertCreated()->json('data.benefit.id');
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->where('id', $id)->update(['percentage' => '1.00']));
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->where('id', $id)->delete());
        $this->rejectSql(fn () => DB::statement('TRUNCATE insurance_benefit_rules'));
        [, $other, $otherUrl] = $this->patientScope();
        $this->postJson($otherUrl, $this->input(['supersedes_id' => $id]))->assertNotFound();
        $raw = (array) DB::table('insurance_benefit_rules')->find($id);
        unset($raw['id']);
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->insert([...$raw, 'association_id' => $other]));
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->insert([...$raw, 'percentage' => '101.00']));
        $tenant = Tenant::create(['slug' => 'benefit-foreign', 'name' => 'Foreign clinic', 'active' => true]);
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->insert([...$raw, 'tenant_id' => $tenant->id]));
        $this->rejectSql(fn () => DB::table('insurance_benefit_rules')->insert([...$raw, 'supersedes_id' => $id, 'revision' => 4]));
    }

    public function test_draft_plan_corrections_are_full_revisions_and_currency_changes_fail_validation(): void
    {
        $this->actor();
        [$id, $url] = $this->planScope();
        $first = $this->postJson($url, $this->input(['source' => 'insurer_plan', 'expected_version' => 1]))->assertCreated()->json('data.benefit.id');
        $this->postJson($url, $this->input(['source' => 'insurer_plan', 'expected_version' => 2, 'supersedes_id' => $first,
            'benefit_type' => 'fixed', 'percentage' => null, 'amount' => '50.00', 'currency' => 'JMD', 'reason' => 'Explicit correction']))
            ->assertCreated()->assertJsonPath('data.benefit.revision', 2)->assertJsonPath('data.benefit.amount', '50.00');
        $this->getJson('/api/insurance/plan-versions/'.$id)->assertOk()->assertJsonPath('data.plan_version.benefits_configured', true)->assertJsonPath('data.plan_version.lock_version', 3);
        $this->patchJson('/api/insurance/plan-versions/'.$id, ['expected_version' => 3, 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson($url, $this->input(['source' => 'insurer_plan', 'expected_version' => 3, 'annual_limit' => '100.00', 'currency' => 'USD']))->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseHas('insurance_benefit_rules', ['id' => $first, 'percentage' => '75.25']);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_exact_permissions_patient_and_tenant_access_are_enforced_in_http_and_direct_workflow(): void
    {
        $owner = $this->actor();
        [$patient, $association, $url] = $this->patientScope();
        $id = $this->postJson($url, $this->input())->assertCreated()->json('data.benefit.id');
        [$version, $planUrl] = $this->planScope();
        $this->actor(['patients.view', 'patient_insurance.view', 'insurance_catalog.view']);
        $this->getJson($url)->assertOk();
        $this->postJson($url, $this->input())->assertForbidden();
        $this->getJson($url.'/'.$id.'/details')->assertForbidden();
        $this->postJson($planUrl, $this->input(['source' => 'insurer_plan', 'expected_version' => 1]))->assertForbidden();
        $this->actor(['patient_insurance.view']);
        $this->getJson($url)->assertForbidden();
        $wildcard = $this->actor(['*', 'patient_insurance.*', 'insurance_catalog.*']);
        $this->getJson($url)->assertForbidden();
        $tenant = Tenant::findOrFail(app(TenantContext::class)->id());
        try {
            app(TenantContext::class)->run($tenant, fn () => app(InsuranceBenefitWorkflow::class)->record(PatientInsurerAssociation::findOrFail($association), $this->input(), $wildcard, $patient));
            $this->fail('Exact permissions required in workflow.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Sanctum::actingAs($owner);
        $otherPatient = Patient::factory()->create();
        $this->getJson('/api/patients/'.$otherPatient->id.'/insurance-associations/'.$association.'/benefits')->assertNotFound();
        $foreign = Tenant::create(['slug' => 'other-benefit-clinic', 'name' => 'Other clinic', 'active' => true]);
        app(TenantContext::class)->run($foreign, function () use ($url, $planUrl) {
            $this->actor();
            $this->getJson($url)->assertNotFound();
            $this->getJson($planUrl)->assertNotFound();
        });
    }

    public function test_partial_policy_revision_is_pinned_and_wrong_association_policy_is_rejected(): void
    {
        $this->actor();
        [, , $url] = $this->patientScope();
        $policyUrl = str_replace('/benefits', '/policy-revisions', $url);
        $policy = $this->postJson($policyUrl, ['expected_version' => 0, 'membership_id' => 'SYNTHETIC-MEMBER', 'reason' => 'Partial policy'])->assertCreated()->json('data.revision.id');
        $this->postJson($url, $this->input(['policy_revision_id' => $policy]))->assertCreated()->assertJsonPath('data.benefit.policy_revision_id', $policy);
        [, , $otherUrl] = $this->patientScope();
        $this->postJson($otherUrl, $this->input(['policy_revision_id' => $policy]))->assertNotFound();
        $this->assertDatabaseCount('insurance_plan_versions', 0);
    }

    public function test_failed_audit_rolls_back_rule_and_plan_editor_changes_and_blocks_disclosure(): void
    {
        $actor = $this->actor();
        [$patient, $association, $url] = $this->patientScope();
        $id = $this->postJson($url, $this->input())->assertCreated()->json('data.benefit.id');
        [$version] = $this->planScope();
        $actor = $this->actor();
        $mock = Mockery::mock(AuditLogService::class);
        $mock->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        $workflow = new InsuranceBenefitWorkflow(app(TenantContext::class), $mock);
        $tenant = Tenant::findOrFail(app(TenantContext::class)->id());
        foreach ([
            fn () => $workflow->record(InsurancePlanVersion::findOrFail($version), $this->input(['source' => 'insurer_plan', 'expected_version' => 1]), $actor),
            fn () => $workflow->record(PatientInsurerAssociation::findOrFail($association), $this->input(), $actor, $patient),
            fn () => $workflow->details(PatientInsurerAssociation::findOrFail($association), $id, $actor, $patient),
            fn () => $workflow->listing(PatientInsurerAssociation::findOrFail($association), [], $actor, $patient),
        ] as $operation) {
            try {
                app(TenantContext::class)->run($tenant, $operation);
                $this->fail('Audit must succeed before access or changes.');
            } catch (RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('insurance_benefit_rules', 1);
        $this->assertDatabaseHas('insurance_plan_versions', ['id' => $version, 'lock_version' => 1]);
        $this->assertDatabaseMissing('insurance_version_editors', ['insurance_plan_version_id' => $version, 'user_id' => $actor->id]);
    }

    public function test_plan_failure_logs_redact_private_exception_content(): void
    {
        $this->actor();
        [, $url] = $this->planScope();
        $mock = Mockery::mock(AuditLogService::class);
        $mock->shouldReceive('record')->andThrow(new RuntimeException('PRIVATE-BENEFIT-CONTENT'));
        $this->app->instance(AuditLogService::class, $mock);
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $route->flushController();
        }
        \Illuminate\Support\Facades\Log::spy();
        $this->postJson($url, $this->input(['source' => 'insurer_plan', 'expected_version' => 1]))->assertStatus(500)
            ->assertJsonMissing(['PRIVATE-BENEFIT-CONTENT']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            $this->assertSame(RuntimeException::class, $context['exception_class']);
            $this->assertArrayNotHasKey('exception', $context);
            $this->assertStringNotContainsString('PRIVATE-BENEFIT-CONTENT', json_encode($context));

            return true;
        });
        $this->assertDatabaseCount('insurance_benefit_rules', 0);
    }

    public function test_benefits_do_not_modify_existing_financial_records_or_activate_evidence(): void
    {
        $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        Payment::factory()->for($invoice)->create();
        $before = [];
        foreach (['invoices', 'invoice_items', 'payments', 'patient_documents'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        [, , $url] = $this->patientScope();
        $this->postJson($url, $this->input())->assertCreated();
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertFalse(config('insurance_evidence.uploads_enabled'));
        $this->assertFalse(config('insurance_evidence.linking_enabled'));
        $migration = require database_path('migrations/2026_11_16_000001_create_insurance_benefit_rules.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
