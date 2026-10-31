<?php

namespace Tests\Feature\Api;

use App\Documents\DocumentStorage;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientDocumentWorkflow;
use App\Tenancy\TenantContext;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PatientDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('clinical_documents');
    }

    #[DataProvider('endpoints')]
    public function test_authentication_is_required(string $method, string $suffix): void
    {
        $patient = Patient::factory()->create();
        $this->json($method, $this->url($patient).$suffix)->assertUnauthorized();
    }

    public static function endpoints(): array
    {
        return [['GET', ''], ['POST', ''], ['GET', '/1'], ['GET', '/1/download'], ['POST', '/1/archive'], ['DELETE', '/1']];
    }

    public function test_unprivileged_members_cannot_access_any_document_operation(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->assertCreated()->json('data.document.id');
        $this->actor(null);
        foreach ([['GET', ''], ['POST', ''], ['GET', "/$id"], ['GET', "/$id/download"], ['POST', "/$id/archive"], ['DELETE', "/$id"]] as [$method, $suffix]) {
            $this->json($method, $this->url($patient).$suffix)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('clinicalRoles')]
    public function test_complete_lifecycle_and_private_metadata(string $role): void
    {
        $actor = $this->actor($role);
        $appointment = Appointment::factory()->create();
        $patient = Patient::findOrFail($appointment->patient_id);
        $note = ClinicalNote::factory()->create(['patient_id' => $patient->id, 'dentist_id' => $appointment->dentist_id, 'appointment_id' => $appointment->id]);
        $response = $this->upload($patient, ['appointment_id' => $appointment->id, 'clinical_note_id' => $note->id])->assertCreated()
            ->assertJsonPath('data.document.patient_id', $patient->id)
            ->assertJsonPath('data.document.appointment_id', $appointment->id)
            ->assertJsonPath('data.document.clinical_note_id', $note->id)
            ->assertJsonPath('data.document.original_filename', 'scan.pdf')
            ->assertJsonPath('data.document.mime_type', 'application/pdf')
            ->assertJsonPath('data.document.size_bytes', strlen($this->pdf()))
            ->assertJsonPath('data.document.uploaded_by_user_id', $actor->id)
            ->assertJsonStructure(['data' => ['document' => ['created_at', 'updated_at', 'archived_at']]])
            ->assertJsonMissingPath('data.document.storage_key')->assertJsonMissingPath('data.document.disk')->assertJsonMissingPath('data.document.url');
        $id = $response->json('data.document.id');
        $document = PatientDocument::findOrFail($id);
        Storage::disk('clinical_documents')->assertExists($document->storage_key);
        $this->assertStringNotContainsString('scan.pdf', $document->storage_key);
        $this->assertStringStartsWith('tenants/'.$document->tenant_id.'/patients/'.$patient->id.'/', $document->storage_key);
        $this->getJson($this->url($patient))->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson($this->url($patient)."/$id")->assertOk();
        $download = $this->getJson($this->url($patient)."/$id/download")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame($this->pdf(), $download->streamedContent());
        $archive = $this->postJson($this->url($patient)."/$id/archive")->assertOk()->assertJsonPath('data.document.archived_by_user_id', $actor->id);
        $this->postJson($this->url($patient)."/$id/archive")->assertOk()->assertJsonPath('data.document.archived_at', $archive->json('data.document.archived_at'));
        $this->getJson($this->url($patient))->assertOk()->assertJsonCount(0, 'data.documents');
        $this->getJson($this->url($patient).'?status=archived')->assertOk()->assertJsonCount(1, 'data.documents');
        $this->getJson($this->url($patient).'?status=all')->assertOk()->assertJsonCount(1, 'data.documents');
        $this->getJson($this->url($patient)."/$id/download")->assertOk();
        $this->deleteJson($this->url($patient)."/$id")->assertOk();
        $this->deleteJson($this->url($patient)."/$id")->assertOk();
        Storage::disk('clinical_documents')->assertMissing($document->storage_key);
        $this->getJson($this->url($patient)."/$id")->assertNotFound();
        $this->getJson($this->url($patient)."/$id/download")->assertNotFound();
        $this->postJson($this->url($patient)."/$id/archive")->assertNotFound();
        $this->assertNotNull($document->fresh()->purged_at);
        $this->assertSame($actor->id, $document->fresh()->deleted_by_user_id);
        foreach (['uploaded', 'archived', 'deleted', 'purged'] as $action) {
            $this->assertSame(1, AuditLog::where('resource_type', 'patient_documents')->where('resource_id', (string) $id)->where('action', $action)->count());
        }
        $this->assertStringNotContainsString('scan.pdf', AuditLog::all()->toJson());
    }

    public static function clinicalRoles(): array
    {
        return [[Role::ADMIN], [Role::DENTIST]];
    }

    #[DataProvider('forbiddenWriters')]
    public function test_wildcards_cannot_grant_clinical_writes(string $role): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $user = $this->actor($role, '*');
        if ($role === Role::RECEPTIONIST) {
            $user->roles()->attach(Role::firstOrCreate(['slug' => Role::DENTIST], ['name' => 'Dentist']));
        }
        $this->upload($patient)->assertForbidden();
        $this->postJson($this->url($patient)."/$id/archive")->assertForbidden();
        $this->deleteJson($this->url($patient)."/$id")->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function forbiddenWriters(): array
    {
        return [[Role::RECEPTIONIST], [Role::HYGIENIST], [Role::BILLING], [Role::AUDITOR], ['custom']];
    }

    public function test_explicit_view_permission_allows_metadata_and_download_only(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $this->actor(Role::AUDITOR, 'patient_documents.view');
        $this->getJson($this->url($patient))->assertOk();
        $this->getJson($this->url($patient)."/$id")->assertOk();
        $this->getJson($this->url($patient)."/$id/download")->assertOk();
        $this->upload($patient)->assertForbidden();
    }

    public function test_invalid_files_and_spoofed_mime_types_are_rejected_before_storage(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $invalid = [
            null,
            UploadedFile::fake()->createWithContent('scan.pdf', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('scan.exe', $this->pdf()),
            UploadedFile::fake()->create('oversize.pdf', 20481, 'application/pdf'),
            UploadedFile::fake()->createWithContent('empty.pdf', ''),
            UploadedFile::fake()->createWithContent(str_repeat('a', 255).'.pdf', $this->pdf()),
            UploadedFile::fake()->createWithContent("bad\nname.pdf", $this->pdf()),
        ];
        foreach ($invalid as $file) {
            $this->postJson($this->url($patient), ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $spoof = UploadedFile::fake()->createWithContent('scan.pdf', 'Plain text claiming to be a PDF');
        $spoof->mimeType('application/pdf');
        $this->postJson($this->url($patient), ['file' => $spoof])->assertUnprocessable();
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('clinical_documents')->allFiles());
    }

    public function test_size_limit_and_actual_image_content_are_validated(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $this->postJson($this->url($patient), ['file' => UploadedFile::fake()->createWithContent('xray.png', $png)])
            ->assertCreated()->assertJsonPath('data.document.mime_type', 'image/png')->assertJsonPath('data.document.size_bytes', strlen($png));
        config(['patient_documents.max_size_kb' => 1]);
        $this->postJson($this->url($patient), ['file' => UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf().str_repeat(' ', 1024))])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_attribution_paths_and_timestamps_are_server_controlled(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        foreach (['id', 'patient_id', 'disk', 'storage_key', 'url', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by_user_id', 'archived_by_user_id', 'deleted_by_user_id', 'created_at', 'updated_at', 'archived_at', 'deleted_at', 'purged_at'] as $field) {
            $this->upload($patient, [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('patient_documents', 0);
    }

    public function test_patient_and_appointment_note_links_must_agree(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $otherAppointment = Appointment::factory()->create();
        $otherNote = ClinicalNote::factory()->create();
        $this->upload($patient, ['appointment_id' => $otherAppointment->id])->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->upload($patient, ['clinical_note_id' => $otherNote->id])->assertUnprocessable()->assertJsonValidationErrors('clinical_note_id');
        $first = Appointment::factory()->create(['patient_id' => $patient->id]);
        $second = Appointment::factory()->create(['patient_id' => $patient->id]);
        $note = ClinicalNote::factory()->create(['patient_id' => $patient->id, 'appointment_id' => $first->id, 'dentist_id' => $first->dentist_id]);
        $this->upload($patient, ['appointment_id' => $second->id, 'clinical_note_id' => $note->id])->assertUnprocessable()->assertJsonValidationErrors('clinical_note_id');
        $note->delete();
        $this->upload($patient, ['clinical_note_id' => $note->id])->assertUnprocessable();
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertSame([], Storage::disk('clinical_documents')->allFiles());
    }

    public function test_foreign_clinic_and_wrong_patient_ids_never_expose_documents(): void
    {
        $this->actor(Role::ADMIN, '*');
        $local = Patient::factory()->create();
        $otherLocal = Patient::factory()->create();
        $id = $this->upload($local)->json('data.document.id');
        $foreignTenant = Tenant::factory()->create();
        [$foreignPatient, $appointment, $note] = app(TenantContext::class)->run($foreignTenant, fn () => [Patient::factory()->create(), Appointment::factory()->create(), ClinicalNote::factory()->create()]);
        foreach ([['GET', "/$id"], ['GET', "/$id/download"], ['POST', "/$id/archive"], ['DELETE', "/$id"]] as [$method, $suffix]) {
            $this->json($method, $this->url($otherLocal).$suffix)->assertNotFound();
            $this->json($method, $this->url($foreignPatient).$suffix)->assertNotFound();
        }
        $this->upload($foreignPatient)->assertNotFound();
        $this->upload($local, ['appointment_id' => $appointment->id])->assertUnprocessable();
        $this->upload($local, ['clinical_note_id' => $note->id])->assertUnprocessable();
        $this->getJson($this->url($local), ['X-Tenant-Id' => $foreignTenant->id])->assertForbidden();
        $this->assertDatabaseCount('patient_documents', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_two_populated_clinics_remain_isolated_when_a_shared_user_switches_clinics(): void
    {
        $actor = $this->actor(Role::ADMIN, '*');
        $local = Patient::factory()->create();
        $localId = $this->upload($local)->assertCreated()->json('data.document.id');
        $tenant = Tenant::factory()->create();
        $actor->tenants()->attach($tenant);
        [$foreign, $role] = app(TenantContext::class)->run($tenant, fn () => [
            Patient::factory()->create(), Role::create(['name' => 'Admin', 'slug' => Role::ADMIN]),
        ]);
        app(TenantContext::class)->run($tenant, fn () => $actor->roles()->attach($role));
        $foreignId = $this->postJson($this->url($foreign), ['file' => $this->file()], ['X-Tenant-Id' => $tenant->id])
            ->assertCreated()->json('data.document.id');
        $this->getJson($this->url($local))->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.documents.0.id', $localId);
        $this->getJson($this->url($foreign), ['X-Tenant-Id' => $tenant->id])->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.documents.0.id', $foreignId);
        foreach ([['GET', ''], ['GET', '/download'], ['POST', '/archive'], ['DELETE', '']] as [$method, $suffix]) {
            $this->json($method, $this->url($local)."/$foreignId".$suffix)->assertNotFound();
            $this->json($method, $this->url($foreign)."/$localId".$suffix, [], ['X-Tenant-Id' => $tenant->id])->assertNotFound();
        }
        app(TenantContext::class)->run($tenant, fn () => $actor->roles()->detach($role));
        $this->getJson($this->url($foreign)."/$foreignId/download", ['X-Tenant-Id' => $tenant->id])->assertForbidden();
        $this->getJson($this->url($local)."/$localId/download")->assertOk();
        $actor->tenants()->detach($tenant);
        $this->getJson($this->url($foreign)."/$foreignId/download", ['X-Tenant-Id' => $tenant->id])->assertForbidden();
    }

    public function test_linked_parents_cannot_be_reassigned_and_historical_note_deletion_preserves_access(): void
    {
        $this->actor(Role::ADMIN);
        $appointment = Appointment::factory()->create();
        $patient = Patient::findOrFail($appointment->patient_id);
        $note = ClinicalNote::factory()->create(['patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'dentist_id' => $appointment->dentist_id]);
        $id = $this->upload($patient, ['appointment_id' => $appointment->id, 'clinical_note_id' => $note->id])->assertCreated()->json('data.document.id');
        $other = Patient::factory()->create();
        $this->patchJson('/api/appointments/'.$appointment->id, ['patient_id' => $other->id])->assertUnprocessable();
        $this->patchJson('/api/clinical-notes/'.$note->id, ['patient_id' => $other->id, 'appointment_id' => null])->assertUnprocessable();
        $second = Appointment::factory()->create(['patient_id' => $patient->id, 'dentist_id' => $appointment->dentist_id, 'appointment_date_time' => now()->addDays(5)]);
        $this->patchJson('/api/clinical-notes/'.$note->id, ['appointment_id' => $second->id])->assertUnprocessable();
        foreach (['appointment' => $appointment->id, 'clinical_notes' => $note->id] as $table => $parentId) {
            try {
                DB::beginTransaction();
                DB::table($table)->where('id', $parentId)->update(['patient_id' => $other->id]);
                $this->fail('Expected retained document relationship guard.');
            } catch (QueryException $error) {
                DB::rollBack();
                $this->assertStringContainsString('Patient document links must be preserved', $error->getMessage());
            }
        }
        $note->delete();
        $patient->archive();
        $this->getJson($this->url($patient)."/$id/download")->assertOk();
    }

    public function test_upload_and_archive_attribution_survive_staff_departure(): void
    {
        $uploader = $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->assertCreated()->json('data.document.id');
        $this->postJson($this->url($patient)."/$id/archive")->assertOk();
        $uploader->roles()->detach();
        $uploader->tenants()->detach();
        $uploader->delete();
        $this->actor(Role::ADMIN);
        $this->getJson($this->url($patient)."/$id")->assertOk()
            ->assertJsonPath('data.document.uploaded_by_user_id', $uploader->id)
            ->assertJsonPath('data.document.archived_by_user_id', $uploader->id);
        $this->deleteJson($this->url($patient)."/$id")->assertOk();
    }

    public function test_private_objects_have_no_static_route_and_public_disk_is_rejected(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->assertCreated()->json('data.document.id');
        $document = PatientDocument::findOrFail($id);
        $this->get('/storage/'.$document->storage_key)->assertNotFound();
        Storage::disk('public')->assertMissing($document->storage_key);
        config(['filesystems.disks.clinical_documents.visibility' => 'public']);
        $this->getJson($this->url($patient)."/$id/download")->assertStatus(500);
        $this->assertSame(0, AuditLog::where('action', 'downloaded')->count());
    }

    public function test_audit_failure_rolls_back_upload_and_removes_object(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->upload($patient)->assertStatus(500);
        $this->assertDatabaseCount('patient_documents', 0);
        $this->assertSame([], Storage::disk('clinical_documents')->allFiles());
    }

    public function test_failed_purge_revokes_access_and_repeated_delete_retries(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $document = PatientDocument::findOrFail($id);
        $realStorage = app(DocumentStorage::class);
        $this->mock(DocumentStorage::class)->shouldReceive('delete')->once()->andThrow(UnableToDeleteFile::atLocation('private'));
        $this->deleteJson($this->url($patient)."/$id")->assertStatus(503);
        $this->getJson($this->url($patient)."/$id/download")->assertNotFound();
        $this->getJson($this->url($patient).'?status=all')->assertJsonCount(0, 'data.documents');
        Storage::disk('clinical_documents')->assertExists($document->storage_key);
        $this->assertNull($document->fresh()->purged_at);
        $this->app->instance(DocumentStorage::class, $realStorage);
        $this->deleteJson($this->url($patient)."/$id")->assertOk();
        Storage::disk('clinical_documents')->assertMissing($document->storage_key);
        $this->assertSame(1, AuditLog::where('action', 'deleted')->count());
        $this->assertSame(1, AuditLog::where('action', 'purged')->count());
    }

    public function test_audit_failure_prevents_download_archive_and_delete(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $document = PatientDocument::findOrFail($id);
        $this->mock(AuditLogService::class)->shouldReceive('record')->times(3)->andThrow(new RuntimeException('Audit unavailable'));
        $this->getJson($this->url($patient)."/$id/download")->assertStatus(500);
        $this->postJson($this->url($patient)."/$id/archive")->assertStatus(500);
        $this->deleteJson($this->url($patient)."/$id")->assertStatus(500);
        $this->assertNull($document->fresh()->archived_at);
        $this->assertNull($document->fresh()->deleted_at);
        Storage::disk('clinical_documents')->assertExists($document->storage_key);
    }

    public function test_storage_failures_return_generic_errors_without_success_audits(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $storage = $this->mock(DocumentStorage::class);
        $storage->shouldReceive('diskName')->andReturn('clinical_documents');
        $storage->shouldReceive('put')->once()->andThrow(UnableToWriteFile::atLocation('secret-key', 'secret-provider-url'));
        $storage->shouldReceive('delete')->once();
        $storage->shouldReceive('read')->once()->andThrow(UnableToReadFile::fromLocation('secret-key'));
        $this->upload($patient)->assertStatus(503)->assertDontSee('secret-');
        $this->getJson($this->url($patient)."/$id/download")->assertStatus(503)->assertDontSee('secret-');
        $this->assertDatabaseCount('patient_documents', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_database_guards_preserve_ownership_and_uploaded_metadata(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $id = $this->upload($patient)->json('data.document.id');
        $other = Patient::factory()->create();
        foreach ([['patient_id' => $other->id], ['storage_key' => 'other-key'], ['original_filename' => 'changed.pdf'], ['size_bytes' => 1]] as $values) {
            try {
                DB::beginTransaction();
                DB::table('patient_documents')->where('id', $id)->update($values);
                $this->fail('Expected document integrity guard.');
            } catch (QueryException $error) {
                DB::rollBack();
                $this->assertStringContainsString('Invalid patient document ownership or metadata', $error->getMessage());
            }
        }
    }

    public function test_workflow_rejects_direct_unauthorized_callers(): void
    {
        $actor = $this->actor(Role::RECEPTIONIST, '*');
        $patient = Patient::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run(Tenant::where('slug', Tenant::DEFAULT_SLUG)->firstOrFail(), fn () => app(PatientDocumentWorkflow::class)->upload($patient, ['file' => $this->file()], $actor));
    }

    public function test_s3_adapter_sends_private_encrypted_objects_and_streams_without_urls(): void
    {
        Storage::forgetDisk('clinical_documents');
        $handler = new MockHandler([new Result(['ETag' => 'etag']), new Result(['Body' => Utils::streamFor($this->pdf())]), new Result]);
        config([
            'filesystems.disks.clinical_documents.key' => 'test-key',
            'filesystems.disks.clinical_documents.secret' => 'test-secret',
            'filesystems.disks.clinical_documents.bucket' => 'private-clinical-test',
            'filesystems.disks.clinical_documents.handler' => $handler,
        ]);
        $storage = app(DocumentStorage::class);
        $key = 'tenants/1/patients/2/random-uuid';
        $storage->put($key, $this->file());
        $put = $handler->getLastCommand();
        $this->assertSame('PutObject', $put->getName());
        $this->assertSame($key, $put['Key']);
        $this->assertSame('bucket-owner-full-control', $put['ACL']);
        $this->assertSame('AES256', $put['ServerSideEncryption']);
        $this->assertSame('application/pdf', $put['ContentType']);
        $document = (new PatientDocument)->forceFill(['disk' => 'clinical_documents', 'storage_key' => $key]);
        $stream = $storage->read($document);
        $this->assertSame($this->pdf(), stream_get_contents($stream));
        fclose($stream);
        $this->assertSame('GetObject', $handler->getLastCommand()->getName());
        $storage->delete($document);
        $this->assertSame('DeleteObject', $handler->getLastCommand()->getName());
    }

    private function upload(Patient $patient, array $extra = [])
    {
        return $this->postJson($this->url($patient), ['file' => $this->file(), ...$extra]);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function url(Patient $patient): string
    {
        return '/api/patients/'.$patient->id.'/documents';
    }

    private function actor(?string $role = Role::DENTIST, ?string $permission = null): User
    {
        $user = User::factory()->create();
        if ($role !== null || $permission !== null) {
            $roleModel = Role::firstOrCreate(['slug' => $role ?? 'custom'], ['name' => $role ?? 'Custom']);
            $user->roles()->attach($roleModel);
            if ($permission !== null) {
                [$resource, $action] = array_pad(explode('.', $permission, 2), 2, null);
                $grant = Permission::firstOrCreate(['slug' => $permission], ['name' => $permission, 'resource' => $resource, 'action' => $action]);
                $roleModel->permissions()->syncWithoutDetaching([$grant->id]);
            }
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
