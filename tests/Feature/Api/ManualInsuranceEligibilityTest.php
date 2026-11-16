<?php

namespace Tests\Feature\Api;

use App\Documents\EvidenceScanner;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientInsuranceCheck;
use App\Models\PatientInsurerAssociation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientInsuranceWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Verifies M2-A behavior already shipped by M1-B; no duplicate eligibility implementation. */
class ManualInsuranceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
    }

    private function actor(?array $permissions = null, string $role = 'staff'): User
    {
        $actor = User::factory()->create();
        $record = Role::create(['slug' => $role === Role::ADMIN ? $role : 'm2a-staff-'.$actor->id, 'name' => 'Synthetic staff']);
        $actor->roles()->attach($record);
        foreach ($permissions ?? ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'patient_insurance.reveal'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $record->permissions()->attach($permission);
        }
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function association(Patient $patient): int
    {
        return $this->postJson('/api/patients/'.$patient->id.'/insurance-associations', [
            'unverified_insurer_name' => 'Canopy',
        ])->assertCreated()->json('data.association.id');
    }

    private function url(Patient $patient, int $association, string $suffix = '/checks'): string
    {
        return '/api/patients/'.$patient->id.'/insurance-associations/'.$association.$suffix;
    }

    private function observation(array $overrides = []): array
    {
        return array_replace([
            'outcome' => 'active', 'channel' => 'external_card',
            'checked_at' => now()->subMinute()->startOfSecond()->toISOString(),
            'service_date' => '2030-06-15', 'provenance' => 'Synthetic staff report from an external insurer system',
        ], $overrides);
    }

    public static function manualChannels(): array
    {
        return [
            'card unknown' => ['external_card', 'unknown'],
            'portal active' => ['external_portal', 'active'],
            'phone inactive' => ['telephone', 'inactive'],
            'paper unable to verify' => ['paper', 'unable_to_verify'],
            'other manual channel' => ['other', 'active'],
        ];
    }

    #[DataProvider('manualChannels')]
    public function test_provider_only_date_scoped_reports_support_all_channels_and_outcomes(string $channel, string $outcome): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $check = $this->postJson($this->url($patient, $association), $this->observation([
            'channel' => $channel, 'outcome' => $outcome,
        ]))->assertCreated()->assertJsonPath('data.check.channel', $channel)
            ->assertJsonPath('data.check.outcome', $outcome)->assertJsonPath('data.check.service_date', '2030-06-15')
            ->assertJsonPath('data.check.actor_id', $actor->id)->assertJsonPath('data.check.policy_revision_id', null)
            ->assertJsonPath('data.check.source', 'staff_recorded_external')
            ->assertJsonMissingPath('data.check.provenance')->assertJsonMissingPath('data.check.benefit_confidence')
            ->assertHeader('Cache-Control', 'no-store, private')->json('data.check');
        $this->assertNotEmpty($check['recorded_at']);
        $this->assertNotNull($check['association_revision_id']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'external_check_recorded', 'resource_id' => (string) $check['id'], 'actor_id' => $actor->id]);
        $this->getJson($this->url($patient, $association))->assertOk()->assertJsonPath('data.0.id', $check['id']);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('insurance_plan_versions', 0);
        $this->assertDatabaseCount('insurers', 0);
    }

    public function test_service_date_is_independent_of_checked_and_recorded_dates_and_legacy_payloads_still_work(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $input = $this->observation(['checked_at' => '2020-01-01T23:30:00-05:00', 'service_date' => '2030-06-15']);
        $dated = $this->postJson($this->url($patient, $association), $input)->assertCreated()
            ->assertJsonPath('data.check.service_date', '2030-06-15')
            ->assertJsonPath('data.check.checked_at', '2020-01-02T04:30:00.000000Z')->json('data.check');
        $this->assertTrue(Carbon::parse($dated['recorded_at'], 'UTC')->greaterThan(Carbon::parse($dated['checked_at'])));
        unset($input['service_date']);
        $this->postJson($this->url($patient, $association), $input)->assertCreated()->assertJsonPath('data.check.service_date', null);
        $this->postJson($this->url($patient, $association), [...$input, 'service_date' => null])->assertCreated()->assertJsonPath('data.check.service_date', null);
        foreach (['2030-02-30', '15/06/2030', '2030-06-15T00:00:00Z'] as $invalid) {
            $this->postJson($this->url($patient, $association), [...$input, 'service_date' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('service_date');
        }
        $this->assertDatabaseCount('patient_insurance_checks', 3);
    }

    public function test_partial_policy_and_provider_revisions_are_pinned_without_rewriting_prior_checks(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $policyUrl = $this->url($patient, $association, '/policy-revisions');
        $revision = $this->postJson($policyUrl, [
            'expected_version' => 0, 'reason' => 'Only the member ID is known', 'membership_id' => 'SYNTHETIC-MEMBER',
        ])->assertCreated()->assertJsonPath('data.revision.policy_number_masked', null)
            ->assertJsonPath('data.revision.has_subscriber_name', false)->assertJsonPath('data.revision.plan_id', null)->json('data.revision.id');
        $first = $this->postJson($this->url($patient, $association), $this->observation([
            'policy_revision_id' => $revision,
        ]))->assertCreated()->json('data.check');
        $this->postJson($policyUrl, [
            'expected_version' => 1, 'reason' => 'Patient later supplied a policy number', 'policy_number' => 'SYNTHETIC-POLICY',
        ])->assertCreated();
        $this->patchJson($this->url($patient, $association, ''), [
            'expected_version' => 1, 'reason' => 'Staff explicitly recorded priority', 'coverage_priority' => 2,
        ])->assertOk();
        $second = $this->postJson($this->url($patient, $association), $this->observation([
            'outcome' => 'inactive', 'policy_revision_id' => $revision,
        ]))->assertCreated()->json('data.check');
        $this->assertNotSame($first['association_revision_id'], $second['association_revision_id']);
        $this->assertSame($revision, $second['policy_revision_id']);
        $this->assertDatabaseHas('patient_insurance_checks', [
            'id' => $first['id'], 'association_revision_id' => $first['association_revision_id'], 'policy_revision_id' => $revision, 'outcome' => 'active',
        ]);
        $this->assertDatabaseCount('patient_insurance_policies', 1);
        $this->assertDatabaseCount('patient_insurance_policy_revisions', 2);
    }

    public function test_subsequent_observations_are_immutable_history_and_never_a_current_coverage_projection(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $first = $this->postJson($this->url($patient, $association), $this->observation([
            'checked_at' => '2020-01-10T10:00:00Z', 'service_date' => '2020-02-01',
        ]))->assertCreated()->json('data.check');
        $before = DB::table('patient_insurance_checks')->find($first['id']);
        $second = $this->postJson($this->url($patient, $association), $this->observation([
            'checked_at' => '2020-01-05T10:00:00Z', 'service_date' => '2020-02-01', 'outcome' => 'inactive',
            'provenance' => 'Staff recorded a subsequent correction without overwriting the original',
        ]))->assertCreated()->json('data.check');
        $this->getJson($this->url($patient, $association))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second['id'])->assertJsonPath('data.0.checked_at', '2020-01-05T10:00:00.000000Z')
            ->assertJsonPath('data.1.id', $first['id'])->assertJsonPath('data.1.outcome', 'active')
            ->assertJsonMissingPath('data.0.current_eligibility')->assertJsonMissingPath('data.0.guaranteed');
        $this->getJson($this->url($patient, $association, ''))->assertOk()->assertJsonMissingPath('data.association.eligibility_status');
        foreach (['update', 'delete'] as $mutation) {
            DB::beginTransaction();
            try {
                $query = DB::table('patient_insurance_checks')->where('id', $first['id']);
                $mutation === 'update' ? $query->update(['outcome' => 'unknown']) : $query->delete();
                $this->fail('PostgreSQL must reject mutation of an earlier eligibility report.');
            } catch (QueryException $e) {
                $this->assertSame('23514', (string) $e->getCode());
            } finally {
                DB::rollBack();
            }
        }
        $this->assertEquals($before, DB::table('patient_insurance_checks')->find($first['id']));
    }

    public function test_direct_check_and_reveal_services_require_context_membership_and_exact_permissions(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->association($patient);
        $association = PatientInsurerAssociation::findOrFail($id);
        $tenant = Tenant::findOrFail($patient->tenant_id);
        try {
            app(PatientInsuranceWorkflow::class)->recordCheck($patient, $association, $this->observation(), $actor);
            $this->fail('Direct observation writes require explicit tenant context.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $check = app(TenantContext::class)->run($tenant, fn () => app(PatientInsuranceWorkflow::class)->recordCheck(
            $patient, $association, $this->observation(['reference' => 'SYNTHETIC-PRIVATE']), $actor
        ));
        $viewer = $this->actor(['patients.view', 'patient_insurance.view']);
        foreach (['record', 'reveal'] as $operation) {
            try {
                app(TenantContext::class)->run($tenant, fn () => $operation === 'record'
                    ? app(PatientInsuranceWorkflow::class)->recordCheck($patient, $association, $this->observation(), $viewer)
                    : app(PatientInsuranceWorkflow::class)->revealCheck($patient, $association, $check->id, $viewer));
                $this->fail('Exact manage/reveal permission must be required for direct calls.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $wildcard = $this->actor(['*'], Role::ADMIN);
        try {
            app(TenantContext::class)->run($tenant, fn () => app(PatientInsuranceWorkflow::class)->recordCheck($patient, $association, $this->observation(), $wildcard));
            $this->fail('Admin/wildcard does not imply exact insurance authority.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $actor->tenants()->detach();
        try {
            app(TenantContext::class)->run($tenant, fn () => app(PatientInsuranceWorkflow::class)->recordCheck($patient, $association, $this->observation(), $actor));
            $this->fail('Revoked membership must block direct observation writes.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('patient_insurance_checks', 1);
    }

    public function test_observations_and_optional_policy_references_cannot_cross_patient_association_or_tenant(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $otherPatient = Patient::factory()->create();
        $otherAssociation = $this->association($otherPatient);
        $otherRevision = $this->postJson($this->url($otherPatient, $otherAssociation, '/policy-revisions'), [
            'expected_version' => 0, 'reason' => 'Partial external identity', 'membership_id' => 'OTHER-SYNTHETIC',
        ])->assertCreated()->json('data.revision.id');
        $check = $this->postJson($this->url($patient, $association), $this->observation())->assertCreated()->json('data.check.id');
        $this->postJson($this->url($patient, $association), $this->observation(['policy_revision_id' => $otherRevision]))->assertNotFound();
        $this->getJson($this->url($otherPatient, $association))->assertNotFound();
        $this->getJson($this->url($otherPatient, $otherAssociation, '/checks/'.$check.'/details'))->assertNotFound();
        $foreign = Tenant::factory()->create();
        $actor->tenants()->attach($foreign->id);
        $this->getJson($this->url($patient, $association), ['X-Tenant-Id' => (string) $foreign->id])->assertNotFound();
        $this->postJson($this->url($patient, $association), $this->observation([
            'tenant_id' => $foreign->id,
        ]), ['X-Tenant-Id' => (string) $patient->tenant_id])->assertUnprocessable();
        $this->assertDatabaseCount('patient_insurance_checks', 1);
    }

    public function test_private_sources_are_encrypted_and_only_explicit_authorized_reveals_are_audited(): void
    {
        $owner = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $private = ['provenance' => 'SYNTHETIC-PRIVATE-PROVENANCE', 'reference' => 'SYNTHETIC-PRIVATE-REFERENCE'];
        $response = $this->postJson($this->url($patient, $association), $this->observation($private))->assertCreated();
        $id = $response->json('data.check.id');
        $stored = DB::table('patient_insurance_checks')->find($id);
        foreach ($private as $field => $value) {
            $this->assertNotSame($value, $stored->$field);
            $this->assertStringNotContainsString($value, $response->getContent());
            $this->assertStringNotContainsString($value, PatientInsuranceCheck::findOrFail($id)->toJson());
        }
        $this->actor(['patients.view', 'patient_insurance.view']);
        $this->getJson($this->url($patient, $association))->assertOk()->assertJsonMissingPath('data.0.provenance')
            ->assertJsonMissingPath('data.0.reference')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url($patient, $association, '/checks/'.$id.'/details'))->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'check_details_revealed')->count());
        Sanctum::actingAs($owner);
        $this->getJson($this->url($patient, $association, '/checks/'.$id.'/details'))->assertOk()
            ->assertJsonPath('data.details.provenance', $private['provenance'])->assertJsonPath('data.details.reference', $private['reference'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_logs', ['action' => 'check_details_revealed', 'resource_id' => (string) $id, 'actor_id' => $owner->id]);
        foreach ($private as $value) {
            $this->assertStringNotContainsString($value, DB::table('audit_logs')->get()->toJson());
        }
    }

    public function test_failed_observation_or_reveal_audit_cannot_acknowledge_writes_or_expose_private_data(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $id = $this->postJson($this->url($patient, $association), $this->observation([
            'reference' => 'SYNTHETIC-PRIVATE-REFERENCE',
        ]))->assertCreated()->json('data.check.id');
        Log::spy();
        $private = 'SYNTHETIC-SQL-BINDING';
        $this->mock(AuditLogService::class)->shouldReceive('record')->twice()->andThrow(
            new QueryException('pgsql', 'INSERT INTO synthetic VALUES(?)', [$private], new \RuntimeException($private))
        );
        // Earlier fixture requests cached controllers with the original audit dependency.
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $write = $this->postJson($this->url($patient, $association), $this->observation())->assertStatus(500);
        $reveal = $this->getJson($this->url($patient, $association, '/checks/'.$id.'/details'))->assertStatus(500);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-REFERENCE', $reveal->getContent());
        $this->assertStringNotContainsString($private, $write->getContent());
        $this->assertDatabaseCount('patient_insurance_checks', 1);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Unhandled API exception.'
            && isset($context['exception_class']) && ! isset($context['exception']) && ! str_contains(json_encode($context), $private))->twice();
    }

    public function test_dated_active_reports_never_create_benefit_guarantees_cash_or_evidence_authority(): void
    {
        $this->actor(['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'document_holds.manage'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $invoice = Invoice::factory()->issued()->create(['patient_id' => $patient->id]);
        Payment::factory()->for($invoice)->create();
        $before = [];
        foreach (['invoices', 'invoice_items', 'payments'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Storage::fake('clinical_documents');
        $file = fn () => UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $heldDocument = $this->postJson('/api/patients/'.$patient->id.'/documents', ['file' => $file()])->assertCreated()->json('data.document.id');
        $this->postJson('/api/document-evidence/'.$heldDocument.'/holds', [
            'command_key' => (string) Str::uuid(), 'reason' => 'Synthetic retention review',
        ])->assertCreated();
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'quarantined', 'reference' => 'synthetic-test-policy'];
            }
        });
        $quarantined = $this->postJson('/api/patients/'.$patient->id.'/documents', ['file' => $file()])->assertCreated()->json('data.document.id');
        $this->postJson($this->url($patient, $association), $this->observation())->assertCreated()
            ->assertJsonPath('data.check.source', 'staff_recorded_external')->assertJsonMissingPath('data.check.verified_benefits');
        foreach (['benefit_confidence' => 'confirmed', 'insurer_payment' => '100.00', 'guaranteed' => true] as $field => $value) {
            $this->postJson($this->url($patient, $association), $this->observation([$field => $value]))->assertUnprocessable();
        }
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$heldDocument)->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$quarantined.'/download')->assertConflict()->assertJsonPath('code', 'DOCUMENT_QUARANTINED');
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$quarantined)->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        $this->assertFalse(config('insurance_evidence.uploads_enabled'));
        $this->assertFalse(config('insurance_evidence.linking_enabled'));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseCount('patient_insurance_checks', 1);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
    }
}
