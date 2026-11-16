<?php

namespace Tests\Feature\Api;

use App\Models\Dentist;
use App\Models\InsurancePlan;
use App\Models\Insurer;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientInsuranceCheck;
use App\Models\PatientInsurancePolicyRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientInsuranceWorkflow;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientInsuranceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName(), 'Patient insurance tests must use PostgreSQL.');
    }

    private function actor(?array $abilities = null, string $role = 'insurance-test'): User
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $role === Role::ADMIN ? $role : $role.'-'.$actor->id], ['name' => 'Test staff']);
        $actor->roles()->attach($role);
        foreach ($abilities ?? ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'patient_insurance.reveal', 'insurance_catalog.view'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function path(Patient $patient, ?int $association = null, string $suffix = ''): string
    {
        return '/api/patients/'.$patient->id.'/insurance-associations'.($association === null ? '' : '/'.$association).$suffix;
    }

    private function provider(Patient $patient, array $input = []): int
    {
        return $this->postJson($this->path($patient), $input ?: ['unverified_insurer_name' => 'Canopy'])
            ->assertCreated()->json('data.association.id');
    }

    private function checkData(): array
    {
        return ['outcome' => 'active', 'channel' => 'external_card', 'checked_at' => now()->subMinute()->toISOString(),
            'provenance' => 'Staff presented card in the external insurer system', 'reference' => 'PRIVATE-REFERENCE'];
    }

    public function test_registration_updates_booking_check_in_and_treatment_need_no_insurance(): void
    {
        $this->actor([], Role::ADMIN);
        $patientId = $this->postJson('/api/patients', Patient::factory()->raw())->assertCreated()->json('data.patient.id');
        $this->patchJson('/api/patients/'.$patientId, ['city' => 'Kingston'])->assertOk();
        $dentist = Dentist::factory()->create();
        $treatment = Treatment::factory()->create();
        $appointmentId = $this->postJson('/api/appointments', [
            'patient_id' => $patientId, 'dentist_id' => $dentist->id, 'treatment_id' => $treatment->id,
            'appointment_date_time' => now()->addDay()->setTime(9, 0)->toISOString(),
            'appointment_type' => 'Consultation', 'description' => 'Ordinary treatment', 'duration_minutes' => 30,
        ])->assertCreated()->json('data.appointment.id');
        $this->postJson('/api/appointments/'.$appointmentId.'/check-in')->assertOk();
        $this->postJson('/api/clinical-notes', [
            'patient_id' => $patientId, 'dentist_id' => $dentist->id, 'appointment_id' => $appointmentId, 'note_text' => 'Routine clinical care',
        ])->assertCreated();
        $this->postJson('/api/patients/'.$patientId.'/treatment-plans', [
            'title' => 'Routine plan', 'items' => [['treatment_id' => $treatment->id, 'dentist_id' => $dentist->id]],
        ])->assertCreated();
        $this->assertDatabaseCount('patient_insurer_associations', 0);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('patient_insurance_checks', 0);
    }

    public function test_provider_only_and_uninsured_patients_need_no_policy_or_published_plan(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $this->getJson($this->path($patient))->assertOk()->assertJsonCount(0, 'data');
        $id = $this->provider($patient);
        $this->getJson($this->path($patient, $id))->assertOk()
            ->assertJsonPath('data.association.provider_information_status', 'unverified_name')
            ->assertJsonPath('data.association.coverage_priority', null)->assertJsonMissingPath('data.association.eligibility_status');
        $this->assertDatabaseCount('insurers', 0);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('insurance_plan_versions', 0);
    }

    public function test_catalog_reference_and_multiple_insurers_have_only_explicit_priority(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $insurer = Insurer::forceCreate(['code' => 'SAGICOR', 'name' => 'Sagicor']);
        $first = $this->provider($patient, ['insurer_id' => $insurer->id]);
        $second = $this->provider($patient, ['unverified_insurer_name' => 'Canopy', 'coverage_priority' => 2]);
        $this->getJson($this->path($patient).'?per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.coverage_priority', null);
        $this->getJson($this->path($patient, $second))->assertOk()->assertJsonPath('data.association.coverage_priority', 2);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
    }

    public function test_progressive_policy_completion_preserves_omitted_fields_and_immutable_history(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $path = $this->path($patient, $association, '/policy-revisions');
        $first = $this->postJson($path, ['expected_version' => 0, 'reason' => 'Member card supplied', 'membership_id' => 'MEMBER-1234'])
            ->assertCreated()->assertJsonPath('data.revision.policy_number_masked', null)
            ->assertJsonPath('data.revision.membership_id_masked', '****1234')->json('data.revision.id');
        $this->postJson($path, [
            'expected_version' => 1, 'reason' => 'Additional patient details', 'policy_number' => 'POLICY-5678', 'subscriber_name' => 'Private Subscriber',
            'relationship' => 'child', 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ])->assertCreated()->assertJsonPath('data.revision.version', 2)->assertJsonPath('data.revision.membership_id_masked', '****1234')
            ->assertJsonMissingPath('data.revision.subscriber_name');
        $this->postJson($path, ['expected_version' => 2, 'reason' => 'Correction', 'policy_number' => null])
            ->assertCreated()->assertJsonPath('data.revision.policy_number_masked', null);
        $this->getJson($path)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($path.'/'.$first.'/identifiers')->assertOk()
            ->assertJsonPath('data.identifiers.membership_id', 'MEMBER-1234')->assertJsonPath('data.identifiers.policy_number', null);
        $this->assertDatabaseCount('patient_insurance_policies', 1);
        $this->assertDatabaseCount('patient_insurance_policy_revisions', 3);
    }

    public function test_optional_plan_belongs_to_recorded_insurer_but_needs_no_published_version(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $insurer = Insurer::forceCreate(['code' => 'KNOWN', 'name' => 'Known insurer']);
        $plan = InsurancePlan::forceCreate(['insurer_id' => $insurer->id, 'code' => 'P', 'name' => 'Optional plan']);
        $association = $this->provider($patient, ['insurer_id' => $insurer->id]);
        $this->postJson($this->path($patient, $association, '/policy-revisions'), [
            'expected_version' => 0, 'reason' => 'Plan supplied', 'plan_id' => $plan->id,
        ])->assertCreated()->assertJsonPath('data.revision.plan_id', $plan->id)->assertJsonPath('data.revision.has_subscriber_name', false);
        $unverified = $this->provider($patient);
        $this->postJson($this->path($patient, $unverified, '/policy-revisions'), [
            'expected_version' => 0, 'reason' => 'Wrong provider', 'plan_id' => $plan->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('insurance_plan_versions', 0);
    }

    public function test_provider_revisions_preserve_unverified_origin_and_do_not_rewrite_observations(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $check = $this->postJson($this->path($patient, $association, '/checks'), $this->checkData())->assertCreated()->json('data.check');
        $insurer = Insurer::forceCreate(['code' => 'CANOPY', 'name' => 'Canopy']);
        $this->patchJson($this->path($patient, $association), [
            'expected_version' => 1, 'reason' => 'Matched catalog explicitly', 'insurer_id' => $insurer->id, 'unverified_insurer_name' => null,
        ])->assertOk()->assertJsonPath('data.association.provider_information_status', 'catalog_reference');
        $this->getJson($this->path($patient, $association, '/history'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.unverified_insurer_name', 'Canopy')->assertJsonMissingPath('data.0.reason');
        $this->getJson($this->path($patient, $association, '/checks'))->assertOk()
            ->assertJsonPath('data.0.association_revision_id', $check['association_revision_id']);
    }

    public function test_manual_card_present_observation_needs_no_policy_and_never_posts_payment(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $before = $invoice->fresh()->getAttributes();
        $association = $this->provider($patient);
        $result = $this->postJson($this->path($patient, $association, '/checks'), $this->checkData())->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.check.actor_id', $actor->id)
            ->assertJsonPath('data.check.source', 'staff_recorded_external')->assertJsonPath('data.check.policy_revision_id', null)
            ->assertJsonMissingPath('data.check.provenance')->assertJsonMissingPath('data.check.reference')->json('data.check');
        $this->assertNotEmpty($result['recorded_at']);
        $this->getJson($this->path($patient, $association, '/checks/'.$result['id'].'/details'))->assertOk()
            ->assertJsonPath('data.details.reference', 'PRIVATE-REFERENCE');
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_identifiers_and_provenance_are_encrypted_masked_hidden_and_audited_on_reveal(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $revision = $this->postJson($this->path($patient, $association, '/policy-revisions'), [
            'expected_version' => 0, 'reason' => 'Private reason', 'policy_number' => 'ABC', 'membership_id' => 'PRIVATE-MEMBER-9876', 'subscriber_name' => 'Secret Subscriber',
        ])->assertCreated()->assertJsonPath('data.revision.policy_number_masked', '****')->json('data.revision.id');
        $raw = DB::table('patient_insurance_policy_revisions')->find($revision);
        foreach (['policy_number' => 'ABC', 'membership_id' => 'PRIVATE-MEMBER-9876', 'subscriber_name' => 'Secret Subscriber', 'reason' => 'Private reason'] as $key => $value) {
            $this->assertNotSame($value, $raw->$key);
            $this->assertStringNotContainsString($value, PatientInsurancePolicyRevision::findOrFail($revision)->toJson());
        }
        $check = $this->postJson($this->path($patient, $association, '/checks'), $this->checkData())->assertCreated()->json('data.check.id');
        $this->assertStringNotContainsString('PRIVATE-REFERENCE', PatientInsuranceCheck::findOrFail($check)->toJson());
        $this->assertNotSame('PRIVATE-REFERENCE', DB::table('patient_insurance_checks')->find($check)->reference);
        $this->getJson($this->path($patient, $association, '/policy-revisions/'.$revision.'/identifiers'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.identifiers.policy_number', 'ABC');
        $this->assertDatabaseHas('audit_logs', ['action' => 'identifiers_revealed', 'resource_id' => (string) $revision]);
        $this->assertStringNotContainsString('Secret Subscriber', DB::table('audit_logs')->get()->toJson());
    }

    public function test_exact_capabilities_and_patient_access_are_required_including_direct_workflows(): void
    {
        $owner = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $revision = $this->postJson($this->path($patient, $association, '/policy-revisions'), ['expected_version' => 0, 'reason' => 'Member', 'membership_id' => 'PRIVATE'])
            ->assertCreated()->json('data.revision.id');
        $this->actor(['patients.view', 'patient_insurance.view']);
        $this->getJson($this->path($patient, $association))->assertOk();
        $this->postJson($this->path($patient), ['unverified_insurer_name' => 'Sagicor'])->assertForbidden();
        $this->getJson($this->path($patient, $association, '/policy-revisions/'.$revision.'/identifiers'))->assertForbidden();
        $this->actor(['patient_insurance.view', 'patient_insurance.manage']);
        $this->getJson($this->path($patient, $association))->assertForbidden();
        $wildcard = $this->actor(['*'], Role::ADMIN);
        $this->getJson($this->path($patient))->assertForbidden();
        try {
            app(TenantContext::class)->run(Tenant::findOrFail($patient->tenant_id), fn () => app(PatientInsuranceWorkflow::class)->create($patient, ['unverified_insurer_name' => 'Sagicor'], $wildcard));
            $this->fail('Wildcard admin is not exact insurance authority.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(PatientInsuranceWorkflow::class)->create($patient, ['unverified_insurer_name' => 'Sagicor'], $owner);
            $this->fail('Direct operations need explicit tenant context.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_foreign_tenants_and_wrong_patient_or_revision_targets_are_not_found(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $other = Patient::factory()->create();
        $otherAssociation = $this->provider($other);
        $revision = $this->postJson($this->path($other, $otherAssociation, '/policy-revisions'), ['expected_version' => 0, 'reason' => 'Member', 'membership_id' => 'OTHER'])
            ->assertCreated()->json('data.revision.id');
        $this->getJson($this->path($other, $association))->assertNotFound();
        $this->getJson($this->path($patient, $association, '/policy-revisions/'.$revision.'/identifiers'))->assertNotFound();
        $this->postJson($this->path($patient, $association, '/checks'), [...$this->checkData(), 'policy_revision_id' => $revision])->assertNotFound();
        $foreign = Tenant::factory()->create();
        $actor->tenants()->attach($foreign->id);
        $this->getJson($this->path($patient, $association), ['X-Tenant-Id' => (string) $foreign->id])->assertNotFound();
        $foreignInsurer = app(TenantContext::class)->run($foreign, fn () => Insurer::forceCreate(['code' => 'FOREIGN', 'name' => 'Other clinic']));
        $this->postJson($this->path($patient), ['insurer_id' => $foreignInsurer->id], ['X-Tenant-Id' => (string) $patient->tenant_id])->assertNotFound();
    }

    public function test_stale_versions_invalid_partial_dates_and_forged_fields_fail_without_history_changes(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $this->patchJson($this->path($patient, $association), ['expected_version' => 1, 'reason' => 'Explicit priority', 'coverage_priority' => 1])->assertOk();
        $this->patchJson($this->path($patient, $association), ['expected_version' => 1, 'reason' => 'Stale', 'coverage_priority' => 2])->assertConflict();
        $path = $this->path($patient, $association, '/policy-revisions');
        $this->postJson($this->path($patient), [])->assertUnprocessable();
        $this->postJson($path, ['expected_version' => 0, 'reason' => 'No known details', 'membership_id' => null])->assertUnprocessable();
        $this->postJson($path, ['expected_version' => 0, 'reason' => 'Known expiry only', 'expiry_date' => '2026-01-01'])->assertCreated();
        $this->postJson($path, ['expected_version' => 1, 'reason' => 'Invalid combined dates', 'effective_date' => '2026-02-01'])->assertUnprocessable();
        $this->postJson($path, ['expected_version' => 0, 'reason' => 'Stale', 'policy_number' => 'X'])->assertConflict();
        foreach (['tenant_id' => 999, 'patient_id' => 999, 'actor_id' => 999, 'verified' => true, 'card_data' => 'SECRET'] as $key => $value) {
            $this->postJson($this->path($patient), ['unverified_insurer_name' => 'Sagicor', $key => $value])->assertUnprocessable();
        }
        $this->postJson($this->path($patient, $association, '/checks'), [...$this->checkData(), 'checked_at' => now()->addDay()->toISOString()])->assertUnprocessable();
        $this->assertDatabaseCount('patient_insurer_revisions', 2);
        $this->assertDatabaseCount('patient_insurance_policy_revisions', 1);
    }

    public function test_mandatory_audit_rolls_back_writes_and_sensitive_exception_logging_is_redacted(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $private = 'SENSITIVE-MEMBER-9876';
        Log::spy();
        $this->mock(AuditLogService::class)->shouldReceive('record')->andThrow(new QueryException('pgsql', 'INSERT INTO test VALUES(?)', [$private], new \RuntimeException($private)));
        $response = $this->postJson($this->path($patient, $association, '/policy-revisions'), [
            'expected_version' => 0, 'reason' => 'Private policy reason', 'membership_id' => $private,
        ])->assertStatus(500);
        $this->assertStringNotContainsString($private, $response->getContent());
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('patient_insurance_policy_revisions', 0);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Unhandled API exception.'
            && isset($context['exception_class']) && ! isset($context['exception']) && ! str_contains(json_encode($context), $private))->once();
    }

    public function test_permission_seeding_never_grants_insurance_authority_to_admin_by_default(): void
    {
        $actor = $this->actor([], Role::ADMIN);
        $this->seed(PermissionSeeder::class);
        foreach (['view', 'manage', 'reveal'] as $ability) {
            $this->assertDatabaseHas('permissions', ['slug' => 'patient_insurance.'.$ability]);
            $this->assertNotContains('patient_insurance.'.$ability, $actor->fresh()->permissionSlugs());
        }
    }

    public function test_revoked_membership_blocks_direct_insurance_operations(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $actor->tenants()->detach();
        try {
            app(TenantContext::class)->run(Tenant::findOrFail($patient->tenant_id), fn () => app(PatientInsuranceWorkflow::class)->create($patient, ['unverified_insurer_name' => 'Canopy'], $actor));
            $this->fail('Revoked clinic membership must block direct writes.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('patient_insurer_associations', 0);
    }

    public function test_all_new_routes_require_authentication(): void
    {
        $patient = Patient::factory()->create();
        foreach ([
            ['GET', ''], ['POST', ''], ['GET', '/1'], ['PATCH', '/1'], ['GET', '/1/history'],
            ['GET', '/1/policy-revisions'], ['POST', '/1/policy-revisions'], ['GET', '/1/policy-revisions/1/identifiers'],
            ['GET', '/1/checks'], ['POST', '/1/checks'], ['GET', '/1/checks/1/details'],
        ] as [$method, $suffix]) {
            $this->json($method, $this->path($patient).$suffix)->assertUnauthorized();
        }
    }

    private function rejectSql(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('Database must reject the invalid patient insurance mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_postgres_guards_protect_patient_tenant_history_and_revision_integrity(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->provider($patient);
        $this->rejectSql(fn () => DB::table('patient_insurer_revisions')->where('association_id', $association)->update(['unverified_insurer_name' => 'Rewritten']));
        $this->rejectSql(fn () => DB::table('patient_insurer_associations')->where('id', $association)->delete());
        $this->rejectSql(function () use ($association) {
            DB::table('patient_insurer_associations')->where('id', $association)->update(['lock_version' => 2, 'unverified_insurer_name' => 'Rewritten']);
            DB::unprepared('SET CONSTRAINTS patient_insurance_parent_history IMMEDIATE');
        });
        $foreign = Tenant::factory()->create();
        $this->rejectSql(fn () => DB::table('patient_insurer_associations')->insert([
            'tenant_id' => $foreign->id, 'patient_id' => $patient->id, 'unverified_insurer_name' => 'Foreign', 'created_by_user_id' => $actor->id,
        ]));
        $this->rejectSql(fn () => DB::table('patient_insurer_associations')->insert([
            'tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'created_by_user_id' => $actor->id,
        ]));
        $migration = require database_path('migrations/2026_11_15_000001_create_patient_insurance.php');
        try {
            $migration->down();
            $this->fail('Populated insurance history cannot be rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve patient insurance history', $e->getMessage());
        }
        $this->assertDatabaseCount('patient_insurer_associations', 1);
    }
}
