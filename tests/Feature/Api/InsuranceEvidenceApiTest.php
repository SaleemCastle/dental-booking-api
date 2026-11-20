<?php

namespace Tests\Feature\Api;

use App\Documents\DocumentProtection;
use App\Documents\DocumentStorage;
use App\Documents\EvidenceScanner;
use App\Exceptions\DocumentProtectionConflict;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceCatalogWorkflow;
use App\Services\InsuranceDocumentWorkflow;
use App\Services\PatientDocumentWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class InsuranceEvidenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('clinical_documents');
        config(['insurance_evidence.uploads_enabled' => true]);
    }

    protected function actor(array $slugs = [], string $role = 'evidence-test'): User
    {
        $user = User::factory()->create();
        $r = Role::firstOrCreate(['slug' => in_array($role, [Role::ADMIN, Role::DENTIST, Role::BILLING, Role::RECEPTIONIST, Role::HYGIENIST, Role::AUDITOR], true) ? $role : $role.'-'.$user->id], ['name' => 'Evidence test']);
        $user->roles()->attach($r);
        foreach ($slugs as $slug) {
            $p = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $r->permissions()->syncWithoutDetaching([$p->id]);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    protected function clinical(Patient $patient): PatientDocument
    {
        $id = $this->postJson('/api/patients/'.$patient->id.'/documents', ['file' => $this->file()])->assertCreated()->json('data.document.id');

        return PatientDocument::findOrFail($id);
    }

    protected function file(string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n".$extra);
    }

    protected function cleanScanner(): void
    {
        config(['insurance_evidence.linking_enabled' => true]);
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'clean', 'reference' => 'test-approved-scanner-and-policy'];
            }
        });
    }

    protected function insurance(?Patient $patient = null, array $extra = []): PatientDocument
    {
        $url = $patient === null ? '/api/insurance/documents' : '/api/patients/'.$patient->id.'/insurance-documents';
        $id = $this->postJson($url, ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Test source', ...$extra])->assertCreated()->json('data.document.id');

        return PatientDocument::findOrFail($id);
    }

    protected function runInTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run(Tenant::findOrFail(app(TenantContext::class)->id()), $callback);
    }

    protected function rejectSql(callable $callback): void
    {
        DB::beginTransaction();
        try {
            $callback();
            $this->fail('Database must reject this evidence mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_default_insurance_upload_is_quarantined_and_no_existing_route_purges_it(): void
    {
        $this->actor(['insurance_documents.upload', 'insurance_documents.tenant_upload', 'insurance_documents.view', 'insurance_documents.tenant_view', 'patients.view'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->insurance($patient);
        $this->assertDatabaseHas('document_protections', ['version_id' => $d->versionRecord->id, 'quarantined' => true]);
        $url = '/api/patients/'.$patient->id.'/documents/'.$d->id;
        foreach ([$url, $url.'/download', '/api/insurance/documents/'.$d->id] as $path) {
            $this->getJson($path)->assertConflict()->assertJsonPath('code', 'DOCUMENT_QUARANTINED');
        }
        $this->getJson('/api/patients/'.$patient->id.'/documents?status=all')->assertOk()->assertJsonCount(0, 'data.documents')->assertJsonPath('meta.pagination.total', 0);
        $this->postJson($url.'/archive')->assertConflict();
        $this->deleteJson($url)->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
        $this->assertNull($d->fresh()->deleted_at);
    }

    public function test_billing_access_filters_clinical_and_mixed_documents_before_counts(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'patients.view'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $clinical = $this->clinical($patient);
        $mixed = $this->insurance($patient, ['classification' => 'mixed']);
        $insurance = $this->insurance($patient);
        $this->insurance(Patient::factory()->create());
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'patients.view'], Role::BILLING);
        $this->getJson('/api/patients/'.$patient->id.'/documents?per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.documents.0.id', $insurance->id);
        foreach ([$clinical, $mixed] as $hidden) {
            $url = '/api/patients/'.$patient->id.'/documents/'.$hidden->id;
            $this->getJson($url)->assertForbidden();
            $this->getJson($url.'/download')->assertForbidden();
            $this->postJson($url.'/archive')->assertForbidden();
            $this->deleteJson($url)->assertForbidden();
        }
        $this->getJson('/api/patients/'.$patient->id.'/insurance-documents')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $insurance->id);
        $this->getJson('/api/insurance/documents/'.$insurance->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/patients/'.$patient->id.'/documents', ['file' => $this->file()])->assertForbidden();
    }

    public function test_versions_are_distinct_immutable_objects_with_hashes_and_idempotent_commands(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $key = (string) Str::uuid();
        $payload = ['file' => $this->file(), 'command_key' => $key, 'provenance' => 'Original source'];
        $first = $this->postJson('/api/insurance/documents', $payload)->assertCreated()->json('data.document');
        $this->postJson('/api/insurance/documents', [...$payload, 'file' => $this->file()])->assertCreated()->assertJsonPath('data.document.id', $first['id']);
        $this->postJson('/api/insurance/documents', [...$payload, 'file' => $this->file('changed')])->assertConflict();
        $this->assertSame(1, AuditLog::where('action', 'upload_replayed')->count());
        $second = $this->postJson('/api/insurance/documents/'.$first['id'].'/versions', ['file' => $this->file('revision'), 'provenance' => 'Corrected source', 'command_key' => (string) Str::uuid(), 'expected_version' => 1])->assertCreated()->assertJsonPath('data.document.version', 2)->json('data.document');
        $this->assertNotSame($first['content_sha256'], $second['content_sha256']);
        $this->assertSame($first['version_id'], $second['predecessor_id']);
        $this->assertNotSame(PatientDocument::find($first['id'])->storage_key, PatientDocument::find($second['id'])->storage_key);
        $this->postJson('/api/insurance/documents/'.$first['id'].'/versions', ['file' => $this->file(), 'provenance' => 'Stale', 'command_key' => (string) Str::uuid(), 'expected_version' => 1])->assertConflict();
        $this->assertDatabaseCount('patient_documents', 2);
        $this->assertCount(2, Storage::disk('clinical_documents')->allFiles());
        $this->rejectSql(fn () => DB::table('document_versions')->where('id', $first['version_id'])->update(['content_sha256' => str_repeat('a', 64)]));
        $this->rejectSql(fn () => DB::table('document_series')->where('id', $first['series_id'])->update(['classification' => 'clinical', 'lock_version' => DB::raw('lock_version+1')]));
        $this->rejectSql(fn () => DB::table('document_versions')->where('id', $first['version_id'])->delete());
    }

    public function test_tenant_owned_files_never_appear_in_patient_lists_and_plan_links_require_catalog_access(): void
    {
        $this->cleanScanner();
        $actor = $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'insurance_documents.link', 'insurance_catalog.manage', 'insurance_catalog.view'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->insurance();
        $planVersion = $this->runInTenant(function () use ($actor) {
            $w = app(InsuranceCatalogWorkflow::class);
            $insurer = $w->createInsurer(['code' => 'EVIDENCE', 'name' => 'Synthetic catalog'], $actor);
            $plan = $w->createPlan($insurer, ['code' => 'P', 'name' => 'Plan'], $actor);

            return $w->createVersion($plan, ['name' => 'Draft', 'currency' => 'JMD', 'effective_from' => '2026-01-01'], $actor);
        });
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['plan_version_id' => $planVersion->id, 'command_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/patients/'.$patient->id.'/documents?status=all')->assertOk()->assertJsonCount(0, 'data.documents');
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view']);
        $this->getJson('/api/insurance/documents')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/insurance/documents/'.$d->id)->assertForbidden();
    }

    public function test_clinical_links_require_both_purposes_and_do_not_grant_source_access(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.link', 'patients.view'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $key = (string) Str::uuid();
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $patient->id, 'command_key' => $key])->assertCreated();
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $patient->id, 'command_key' => $key])->assertCreated();
        $this->assertDatabaseCount('document_evidence_links', 1);
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'patients.view'], Role::BILLING);
        $this->getJson('/api/patients/'.$patient->id.'/documents?status=all')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/insurance/documents/'.$d->id.'/download')->assertForbidden();
        $admin = $this->actor([], Role::ADMIN);
        $admin->roles()->first()->permissions()->detach();
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertForbidden();
    }

    public function test_holds_cover_the_series_replay_safely_and_block_old_delete_and_storage_calls(): void
    {
        $actor = $this->actor(['document_holds.manage'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $body = ['command_key' => (string) Str::uuid(), 'reason' => 'Protected test legal rationale'];
        $hold = $this->postJson('/api/document-evidence/'.$d->id.'/holds', $body)->assertCreated()->assertJsonMissingPath('data.hold.reason')->json('data.hold.id');
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', $body)->assertCreated()->assertJsonPath('data.hold.id', $hold);
        $this->assertStringNotContainsString($body['reason'], DB::table('document_hold_events')->value('reason'));
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        try {
            $this->runInTenant(fn () => app(DocumentStorage::class)->delete($d));
            $this->fail('Direct storage cannot purge retained evidence.');
        } catch (DocumentProtectionConflict) {
            $this->assertTrue(true);
        }
        $this->rejectSql(fn () => DB::table('patient_documents')->where('id', $d->id)->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id]));
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['release_of_id' => $hold, 'command_key' => (string) Str::uuid(), 'reason' => 'Approved release'])->assertCreated();
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertOk();
        $this->assertNotNull($d->fresh()->purged_at);
    }

    public function test_retained_insurance_cannot_be_purged_even_by_admin_wildcards_or_released_holds(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'document_holds.manage', '*'], Role::ADMIN);
        $d = $this->insurance();
        $hold = $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Review'])->assertCreated()->json('data.hold.id');
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['command_key' => (string) Str::uuid(), 'release_of_id' => $hold, 'reason' => 'Release'])->assertCreated();
        $this->deleteJson('/api/insurance/documents/'.$d->id)->assertConflict();
        $this->postJson('/api/insurance/documents/'.$d->id.'/archive')->assertOk();
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
    }

    public function test_unknown_and_client_controlled_ownership_scanner_and_storage_fields_are_rejected(): void
    {
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        foreach (['tenant_id', 'patient_id', 'storage_key', 'scan_status', 'content_sha256', 'owner_scope', 'retain_until', 'purge_state'] as $field) {
            $this->postJson('/api/insurance/documents', ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Source', $field => 'forged'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertCount(0, Storage::disk('clinical_documents')->allFiles());
    }

    public function test_direct_workflow_does_not_treat_admin_wildcards_as_evidence_authority(): void
    {
        $actor = $this->actor(['*'], Role::ADMIN);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->runInTenant(fn () => app(InsuranceDocumentWorkflow::class)->upload(null, ['file' => $this->file()], $actor));
    }

    public function test_cross_tenant_document_and_wrong_patient_targets_are_denied(): void
    {
        $this->cleanScanner();
        $actor = $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'insurance_documents.link', 'patients.view']);
        $patient = Patient::factory()->create();
        $wrong = Patient::factory()->create();
        $d = $this->insurance($patient);
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $wrong->id, 'command_key' => (string) Str::uuid()])->assertNotFound();
        $foreign = Tenant::factory()->create();
        $actor->tenants()->attach($foreign->id);
        $this->getJson('/api/insurance/documents/'.$d->id, ['X-Tenant-Id' => (string) $foreign->id])->assertNotFound();
        $this->rejectSql(fn () => DB::table('document_evidence_links')->insert(['tenant_id' => $foreign->id, 'version_id' => $d->versionRecord->id, 'patient_id' => $patient->id, 'actor_id' => $actor->id, 'command_key' => (string) Str::uuid()]));
    }

    public function test_corrupt_bytes_are_quarantined_and_never_streamed(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $d = $this->insurance();
        Storage::disk('clinical_documents')->put($d->storage_key, 'corrupted');
        $this->getJson('/api/insurance/documents/'.$d->id.'/download')->assertConflict()->assertJsonPath('code', 'DOCUMENT_QUARANTINED');
        $this->assertDatabaseHas('document_protections', ['version_id' => $d->versionRecord->id, 'quarantined' => true]);
        $this->assertSame(0, AuditLog::where('action', 'downloaded')->count());
    }

    public function test_evidence_upload_and_hold_audit_failure_leave_no_partial_history_or_orphans(): void
    {
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $this->mock(AuditLogService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson('/api/insurance/documents', ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Source'])->assertStatus(500);
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_series', 0);
        $this->assertCount(0, Storage::disk('clinical_documents')->allFiles());
    }

    public function test_link_activation_requires_explicit_operational_approval(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'insurance_documents.link', 'patients.view']);
        $patient = Patient::factory()->create();
        $d = $this->insurance($patient);
        config(['insurance_evidence.linking_enabled' => false]);
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $patient->id, 'command_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        $this->assertDatabaseCount('document_evidence_links', 0);
    }

    public function test_missing_context_and_revoked_membership_block_direct_commands(): void
    {
        $actor = $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        try {
            app(InsuranceDocumentWorkflow::class)->upload(null, [], $actor);
            $this->fail('Explicit tenant context is required.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $actor->tenants()->detach();
        try {
            $this->runInTenant(fn () => app(InsuranceDocumentWorkflow::class)->upload(null, [], $actor));
            $this->fail('Revoked membership must block direct service calls.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_manual_quarantine_requires_exact_permission_and_cannot_be_reversed(): void
    {
        $this->cleanScanner();
        $viewer = $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $d = $this->insurance();
        $this->postJson('/api/document-evidence/'.$d->id.'/quarantine')->assertForbidden();
        try {
            $this->runInTenant(fn () => app(DocumentProtection::class)->quarantine($d, $viewer));
            $this->fail('Direct quarantine must authorize its actor.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'document_quarantine.manage']);
        $this->postJson('/api/document-evidence/'.$d->id.'/quarantine')->assertOk();
        $this->postJson('/api/document-evidence/'.$d->id.'/quarantine')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'quarantined')->count());
        $this->rejectSql(fn () => DB::table('document_protections')->where('version_id', $d->versionRecord->id)->update(['quarantined' => false, 'lock_version' => DB::raw('lock_version+1')]));
        $this->getJson('/api/insurance/documents')->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_held_document_direct_legacy_delete_and_audit_failure_cannot_bypass_retention(): void
    {
        $actor = $this->actor(['document_holds.manage'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $this->mock(AuditLogService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Private hold reason'])->assertStatus(500);
        $this->assertDatabaseCount('document_hold_events', 0);
        $this->app->forgetInstance(AuditLogService::class);
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Private hold reason'])->assertCreated();
        try {
            $this->runInTenant(fn () => app(PatientDocumentWorkflow::class)->delete($patient, $d, $actor));
            $this->fail('Direct legacy deletion must respect holds.');
        } catch (DocumentProtectionConflict $e) {
            $this->assertSame('DOCUMENT_RETENTION_BLOCKED', $e->apiCode);
        }
        $this->assertNull($d->fresh()->deleted_at);
    }

    public function test_evidence_link_audit_failure_does_not_leave_a_retention_link(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload', 'insurance_documents.link', 'patients.view']);
        $patient = Patient::factory()->create();
        $d = $this->insurance($patient);
        $this->mock(AuditLogService::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $patient->id, 'command_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertDatabaseCount('document_evidence_links', 0);
    }

    public function test_direct_storage_cannot_normalize_a_forged_path_to_retained_bytes(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $d = $this->insurance();
        $forged = (new PatientDocument)->forceFill(['disk' => $d->disk, 'storage_key' => str_replace('/evidence/', '/evidence/../evidence/', $d->storage_key)]);
        try {
            $this->runInTenant(fn () => app(DocumentStorage::class)->delete($forged));
            $this->fail('Noncanonical object keys must be rejected.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
    }

    public function test_upload_activation_is_closed_until_the_protected_rollout_is_approved(): void
    {
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        config(['insurance_evidence.uploads_enabled' => false]);
        $this->postJson('/api/insurance/documents', ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Source'])->assertConflict()->assertJsonPath('code', 'DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertCount(0, Storage::disk('clinical_documents')->allFiles());
    }

    public function test_patient_evidence_grants_never_imply_tenant_batch_access(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $batch = $this->insurance();
        $this->actor(['insurance_documents.view', 'insurance_documents.upload', 'patients.view'], Role::BILLING);
        $this->getJson('/api/insurance/documents')->assertForbidden();
        $this->getJson('/api/insurance/documents/'.$batch->id)->assertForbidden();
        $this->getJson('/api/insurance/documents/'.$batch->id.'/download')->assertForbidden();
        $this->postJson('/api/insurance/documents', ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Denied batch'])->assertForbidden();
    }

    public function test_flagged_clinical_uploads_are_quarantined_without_opening_legacy_reads(): void
    {
        $this->actor([], Role::ADMIN);
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'quarantined', 'reference' => 'test-flagged-content'];
            }
        });
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $this->getJson('/api/patients/'.$patient->id.'/documents')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$d->id.'/download')->assertConflict();
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertConflict();
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
    }

    public function test_raw_ownership_and_missing_version_writes_cannot_bypass_database_protection(): void
    {
        $actor = $this->actor([], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $this->rejectSql(fn () => DB::table('patient_documents')->where('id', $d->id)->update(['patient_id' => null]));
        $this->rejectSql(fn () => DB::table('patient_documents')->where('id', $d->id)->update(['created_at' => now()->subYear()]));
        $this->rejectSql(fn () => DB::table('patient_documents')->where('id', $d->id)->delete());
        $this->rejectSql(function () use ($actor, $patient): void {
            DB::table('patient_documents')->insert(['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'disk' => 'clinical_documents', 'storage_key' => 'unversioned/'.Str::uuid(), 'original_filename' => 'invalid.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'uploaded_by_user_id' => $actor->id]);
            DB::unprepared('SET CONSTRAINTS evidence_document_insert IMMEDIATE');
        });
    }

    public function test_insurance_only_readers_need_patient_object_access_on_legacy_lists(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.upload', 'patients.view']);
        $patient = Patient::factory()->create();
        $d = $this->insurance($patient);
        $this->actor(['insurance_documents.view'], Role::BILLING);
        $this->getJson('/api/patients/'.$patient->id.'/documents')->assertForbidden();
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertForbidden();
    }

    public function test_clinical_replacement_versions_preserve_original_and_clinical_authority(): void
    {
        $this->actor([], Role::DENTIST);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $hash = $d->versionRecord->content_sha256;
        config(['insurance_evidence.uploads_enabled' => false]);
        $replacement = $this->postJson('/api/patients/'.$patient->id.'/documents/'.$d->id.'/versions', ['file' => $this->file('clinical correction'), 'command_key' => (string) Str::uuid(), 'provenance' => 'Clinical correction', 'expected_version' => 1])->assertCreated()->assertJsonPath('data.document.classification', 'clinical')->assertJsonPath('data.document.version', 2)->json('data.document');
        $this->assertSame($hash, $d->fresh()->versionRecord->content_sha256);
        $this->assertSame($d->versionRecord->id, $replacement['predecessor_id']);
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
        $this->actor(['insurance_documents.view', 'insurance_documents.upload', 'patients.view', 'patient_documents.view'], Role::BILLING);
        $this->postJson('/api/patients/'.$patient->id.'/documents/'.$d->id.'/versions', ['file' => $this->file(), 'command_key' => (string) Str::uuid(), 'provenance' => 'Denied', 'expected_version' => 2])->assertForbidden();
    }

    public function test_replacement_cannot_remove_inherited_evidence_access_or_retention(): void
    {
        $this->cleanScanner();
        $actor = $this->actor(['insurance_documents.view', 'insurance_documents.link', 'patients.view'], Role::ADMIN);
        $patient = Patient::factory()->create();
        $d = $this->clinical($patient);
        $this->postJson('/api/document-evidence/'.$d->id.'/links', ['patient_id' => $patient->id, 'command_key' => (string) Str::uuid()])->assertCreated();
        $id = $this->postJson('/api/patients/'.$patient->id.'/documents/'.$d->id.'/versions', ['file' => $this->file('revision'), 'command_key' => (string) Str::uuid(), 'provenance' => 'Clinical correction', 'expected_version' => 1])->assertCreated()->json('data.document.id');
        $this->actor([], Role::DENTIST);
        $this->getJson('/api/patients/'.$patient->id.'/documents')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$id)->assertForbidden();
        $this->rejectSql(fn () => DB::table('patient_documents')->where('id', $id)->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id]));
    }

    public function test_storage_cannot_overwrite_accepted_version_bytes_even_through_direct_calls(): void
    {
        $this->cleanScanner();
        $this->actor(['insurance_documents.view', 'insurance_documents.tenant_view', 'insurance_documents.upload', 'insurance_documents.tenant_upload']);
        $d = $this->insurance();
        $before = Storage::disk('clinical_documents')->get($d->storage_key);
        try {
            app(DocumentStorage::class)->put($d->storage_key, $this->file('replacement'));
            $this->fail('Accepted version keys must be write-once.');
        } catch (\LogicException) {
            $this->assertSame($before, Storage::disk('clinical_documents')->get($d->storage_key));
        }
        foreach ([str_replace('/evidence/', '/evidence/../evidence/', $d->storage_key), $d->storage_key.'/'] as $forgedKey) {
            try {
                app(DocumentStorage::class)->put($forgedKey, $this->file('replacement'));
                $this->fail('Path aliases cannot overwrite accepted content.');
            } catch (\LogicException) {
                $this->assertSame($before, Storage::disk('clinical_documents')->get($d->storage_key));
            }
        }
    }
}
