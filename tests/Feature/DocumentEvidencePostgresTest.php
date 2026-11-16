<?php

namespace Tests\Feature;

use App\Documents\DocumentStorage;
use App\Documents\EvidenceScanner;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientDocumentWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToDeleteFile;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Nontransactional fixtures so independent connections observe committed rows. Dedicated test DB only. */
class DocumentEvidencePostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('Evidence lifecycle/concurrency tests require PostgreSQL.');
        }
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('clinical_documents');
        config(['insurance_evidence.linking_enabled' => true]);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $role = Role::create(['slug' => Role::ADMIN, 'name' => 'Admin']);
        $actor->roles()->attach($role);
        foreach (['document_holds.manage', 'insurance_documents.view', 'insurance_documents.link', 'patients.view'] as $slug) {
            $permission = Permission::create(['slug' => $slug, 'name' => $slug]);
            $role->permissions()->attach($permission);
        }
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'clean', 'reference' => 'approved-test-policy'];
            }
        });
        Sanctum::actingAs($actor);
        $patient = Patient::factory()->create();
        $file = UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $id = $this->postJson('/api/patients/'.$patient->id.'/documents', ['file' => $file])->assertCreated()->json('data.document.id');

        return [$actor, $patient, PatientDocument::findOrFail($id)];
    }

    private function other(): PDO
    {
        $c = DB::connection()->getConfig();

        return new PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function reject(PDO $pdo, string $sql, string $state): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $this->fail('Concurrent evidence invariant must reject this write.');
        } catch (PDOException $e) {
            $this->assertSame($state, $e->getCode());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_hold_before_deletion_serializes_and_prevents_purge(): void
    {
        [$actor, , $d] = $this->fixture();
        $v = $d->versionRecord;
        $primary = DB::connection()->getPdo();
        $other = $this->other();
        $primary->beginTransaction();
        $primary->exec("INSERT INTO document_hold_events(tenant_id,series_id,action,reason,actor_id,command_key) VALUES($d->tenant_id,$v->series_id,'placed','protected',$actor->id,'".Str::uuid()."')");
        $other->exec("SET lock_timeout='200ms'");
        $delete = "UPDATE patient_documents SET deleted_at=CURRENT_TIMESTAMP,deleted_by_user_id=$actor->id WHERE id=$d->id";
        $this->reject($other, $delete, '55P03');
        $primary->commit();
        $this->reject($other, $delete, '23514');
        $this->assertNull($d->fresh()->deleted_at);
        Storage::disk('clinical_documents')->assertExists($d->storage_key);
    }

    public function test_link_before_deletion_serializes_and_preserves_exact_version(): void
    {
        [$actor, $patient, $d] = $this->fixture();
        $v = $d->versionRecord;
        $primary = DB::connection()->getPdo();
        $other = $this->other();
        $primary->beginTransaction();
        $primary->exec("INSERT INTO document_evidence_links(tenant_id,version_id,patient_id,actor_id,command_key) VALUES($d->tenant_id,$v->id,$patient->id,$actor->id,'".Str::uuid()."')");
        $other->exec("SET lock_timeout='200ms'");
        $delete = "UPDATE patient_documents SET deleted_at=CURRENT_TIMESTAMP,deleted_by_user_id=$actor->id WHERE id=$d->id";
        $this->reject($other, $delete, '55P03');
        $primary->commit();
        $this->reject($other, $delete, '23514');
        $this->assertSame($v->content_sha256, $d->fresh()->versionRecord->content_sha256);
    }

    public function test_committed_purge_fence_rejects_late_holds_links_and_duplicate_workers(): void
    {
        [$actor, $patient, $d] = $this->fixture();
        $v = $d->versionRecord;
        $other = $this->other();
        $real = app(DocumentStorage::class);
        $this->mock(DocumentStorage::class)->shouldReceive('delete')->once()->andReturnUsing(function ($document) use ($other, $real, $actor, $v): void {
            // Storage is invoked after the DB fence commit, while session exclusion is held.
            $key = 'document-series:'.$document->tenant_id.':'.$v->series_id;
            $lock = $other->prepare('SELECT pg_try_advisory_lock(hashtextextended(?,0))');
            $lock->execute([$key]);
            $this->assertFalse($lock->fetchColumn());
            $this->reject($other, "INSERT INTO document_hold_events(tenant_id,series_id,action,reason,actor_id,command_key) VALUES($document->tenant_id,$v->series_id,'placed','late',$actor->id,'".Str::uuid()."')", '23514');
            $this->reject($other, "INSERT INTO document_evidence_links(tenant_id,version_id,patient_id,actor_id,command_key) VALUES($document->tenant_id,$v->id,$document->patient_id,$actor->id,'".Str::uuid()."')", '23514');
            $real->delete($document);
        });
        app(TenantContext::class)->run(Tenant::findOrFail($d->tenant_id), fn () => app(PatientDocumentWorkflow::class)->delete($patient, $d, $actor));
        $this->assertNotNull($d->fresh()->purged_at);
        $this->assertDatabaseCount('document_hold_events', 0);
        $this->assertDatabaseCount('document_evidence_links', 0);
    }

    public function test_crash_after_object_deletion_recovers_without_restoring_access(): void
    {
        [$actor, $patient, $d] = $this->fixture();
        $v = $d->versionRecord;
        DB::transaction(function () use ($d, $v, $actor): void {
            DB::table('patient_documents')->where('id', $d->id)->update(['deleted_at' => now(), 'deleted_by_user_id' => $actor->id]);
            DB::table('document_protections')->where('version_id', $v->id)->update(['purge_state' => 'purge_in_progress', 'lock_version' => 2]);
        });
        app(TenantContext::class)->run(Tenant::findOrFail($d->tenant_id), fn () => app(DocumentStorage::class)->delete($d));
        // Simulate worker death before marking purged_at: new worker retries the same key.
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$d->id.'/download')->assertNotFound();
        $this->postJson('/api/document-evidence/'.$d->id.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Urgent late request'])->assertConflict();
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$d->id)->assertOk();
        $this->assertNotNull($d->fresh()->purged_at);
        $this->assertDatabaseHas('document_protections', ['version_id' => $v->id, 'purge_state' => 'purged']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purged')->count());
    }

    public static function referenceOperations(): array
    {
        return [
            'hold same version' => ['holds', 'hold_placed', false],
            'link same version' => ['links', 'evidence_linked', false],
            'hold sibling version' => ['holds', 'hold_placed', true],
            'link sibling version' => ['links', 'evidence_linked', true],
        ];
    }

    private function sibling(Patient $patient, PatientDocument $document, bool $needed): PatientDocument
    {
        if (! $needed) {
            return $document;
        }
        $id = $this->postJson('/api/patients/'.$patient->id.'/documents/'.$document->id.'/versions', [
            'file' => UploadedFile::fake()->createWithContent('revision.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"),
            'command_key' => (string) Str::uuid(), 'provenance' => 'Clinical correction', 'expected_version' => 1,
        ])->assertCreated()->json('data.document.id');

        return PatientDocument::findOrFail($id);
    }

    /** Execute a competing HTTP request on an independent backend, without timing/sleeps. */
    private function independently(callable $callback): mixed
    {
        $primary = DB::getDefaultConnection();
        config(['database.connections.evidence_race' => DB::connection()->getConfig()]);
        DB::connection('evidence_race')->statement("SET lock_timeout='2s'");
        DB::connection('evidence_race')->statement("SET statement_timeout='5s'");
        $this->assertNotSame(
            DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            DB::connection('evidence_race')->selectOne('SELECT pg_backend_pid() AS pid')->pid,
        );
        DB::setDefaultConnection('evidence_race');
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($primary);
        }
    }

    private function reference(string $operation, PatientDocument $document)
    {
        return $this->postJson('/api/document-evidence/'.$document->id.'/'.$operation, [
            'command_key' => (string) Str::uuid(),
            ...($operation === 'holds' ? ['reason' => 'Retention request'] : ['patient_id' => $document->patient_id]),
        ]);
    }

    private function assertSeriesFenceReleased(PatientDocument $document): void
    {
        $other = $this->other();
        $key = 'document-series:'.$document->tenant_id.':'.$document->versionRecord->series_id;
        $statement = $other->prepare('SELECT pg_try_advisory_lock(hashtextextended(?,0))');
        $statement->execute([$key]);
        $this->assertTrue($statement->fetchColumn());
        $statement = $other->prepare('SELECT pg_advisory_unlock(hashtextextended(?,0))');
        $statement->execute([$key]);
        $this->assertTrue($statement->fetchColumn());
    }

    #[DataProvider('referenceOperations')]
    public function test_reference_workflow_wins_before_delete(string $operation, string $action, bool $sibling): void
    {
        [, $patient, $document] = $this->fixture();
        $purgeTarget = $this->sibling($patient, $document, $sibling);
        $realAudit = app(AuditLogService::class);
        $barrierReached = false;
        $this->mock(AuditLogService::class)->shouldReceive('record')->andReturnUsing(function (...$args) use ($realAudit, $action, $patient, $purgeTarget, &$barrierReached) {
            $entry = $realAudit->record(...$args);
            if ($args[0] === $action) {
                $barrierReached = true;
                // Reference is inserted but not committed. The competing DELETE must return
                // a workflow conflict immediately, rather than waiting on a row/trigger lock.
                $this->assertSame(1, DB::transactionLevel());
                $this->independently(fn () => $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$purgeTarget->id)
                    ->assertConflict()->assertJsonPath('code', 'DOCUMENT_PURGE_IN_PROGRESS'));
            }

            return $entry;
        });
        $this->reference($operation, $document)->assertCreated();
        $this->assertTrue($barrierReached);
        $this->assertSeriesFenceReleased($document);
        // After the winning workflow commits, deletion must recheck retention.
        $this->independently(fn () => $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$purgeTarget->id)
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED'));
        $this->assertSeriesFenceReleased($document);
        $this->assertNull($document->fresh()->deleted_at);
        $this->assertNull($purgeTarget->fresh()->deleted_at);
        Storage::disk('clinical_documents')->assertExists($document->storage_key);
        Storage::disk('clinical_documents')->assertExists($purgeTarget->storage_key);
        $this->assertDatabaseCount($operation === 'holds' ? 'document_hold_events' : 'document_evidence_links', 1);
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', ['deleted', 'purge_started', 'purged'])->count());
    }

    #[DataProvider('referenceOperations')]
    public function test_delete_workflow_wins_before_reference(string $operation, string $action, bool $sibling): void
    {
        [, $patient, $document] = $this->fixture();
        $referenceTarget = $this->sibling($patient, $document, $sibling);
        $realStorage = app(DocumentStorage::class);
        $this->mock(DocumentStorage::class)->shouldReceive('delete')->once()->andReturnUsing(function ($stored) use ($operation, $document, $referenceTarget, $realStorage): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('document_protections', ['version_id' => $document->versionRecord->id, 'purge_state' => 'purge_in_progress']);
            $this->independently(fn () => $this->reference($operation, $referenceTarget)
                ->assertConflict()->assertJsonPath('code', 'DOCUMENT_PURGE_IN_PROGRESS'));
            $realStorage->delete($stored);
        });
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$document->id)->assertOk();
        $this->assertSeriesFenceReleased($document);
        // Also check the committed lifecycle once the advisory lock has gone away.
        $this->independently(fn () => $this->reference($operation, $referenceTarget)
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_PURGE_IN_PROGRESS'));
        $this->assertSeriesFenceReleased($document);
        $this->assertNotNull($document->fresh()->purged_at);
        Storage::disk('clinical_documents')->assertMissing($document->storage_key);
        $this->assertDatabaseCount('document_hold_events', 0);
        $this->assertDatabaseCount('document_evidence_links', 0);
    }

    public function test_storage_failure_releases_series_fence_and_delete_can_retry(): void
    {
        [, $patient, $document] = $this->fixture();
        $realStorage = app(DocumentStorage::class);
        $mock = $this->mock(DocumentStorage::class);
        $mock->shouldReceive('delete')->once()->andThrow(UnableToDeleteFile::atLocation($document->storage_key));
        $url = '/api/patients/'.$patient->id.'/documents/'.$document->id;
        $this->deleteJson($url)->assertStatus(503);
        $this->assertSeriesFenceReleased($document);
        $this->assertDatabaseHas('document_protections', ['version_id' => $document->versionRecord->id, 'purge_state' => 'purge_failed']);
        Storage::disk('clinical_documents')->assertExists($document->storage_key);
        foreach (['holds', 'links'] as $operation) {
            $this->independently(fn () => $this->reference($operation, $document)
                ->assertConflict()->assertJsonPath('code', 'DOCUMENT_PURGE_IN_PROGRESS'));
        }
        $this->app->instance(DocumentStorage::class, $realStorage);
        $this->deleteJson($url)->assertOk();
        $this->assertSeriesFenceReleased($document);
        $this->assertNotNull($document->fresh()->purged_at);
        Storage::disk('clinical_documents')->assertMissing($document->storage_key);
    }

    public function test_reference_exceptions_roll_back_and_release_series_fence(): void
    {
        [, , $document] = $this->fixture();
        $realAudit = app(AuditLogService::class);
        $this->mock(AuditLogService::class)->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Audit unavailable'));
        foreach (['holds', 'links'] as $operation) {
            $this->reference($operation, $document)->assertStatus(500);
            $this->assertSeriesFenceReleased($document);
        }
        $this->assertDatabaseCount('document_hold_events', 0);
        $this->assertDatabaseCount('document_evidence_links', 0);
        $this->app->instance(AuditLogService::class, $realAudit);
        $this->independently(fn () => $this->reference('links', $document)->assertCreated());
        $this->independently(fn () => $this->reference('holds', $document)->assertCreated());
        $this->assertSeriesFenceReleased($document);
    }

    public function test_repeated_fresh_creation_and_empty_rollback_leave_no_orphan_helpers(): void
    {
        // M1-B references the shared patient candidate key owned by M1-C.
        $patientInsurance = require database_path('migrations/2026_11_15_000001_create_patient_insurance.php');
        $patientInsurance->down();
        $migration = require database_path('migrations/2026_11_14_000001_protect_document_evidence.php');
        for ($i = 0; $i < 2; $i++) {
            $migration->down();
            $this->assertNull(DB::selectOne("SELECT to_regclass('document_versions') AS t")->t);
            $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS c FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() AND p.proname LIKE 'evidence_%'")->c);
            $migration->up();
        }
        $patientInsurance->up();
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_backfill_preserves_legacy_bytes_and_unknown_hash_and_refuses_populated_down(): void
    {
        $patientInsurance = require database_path('migrations/2026_11_15_000001_create_patient_insurance.php');
        $patientInsurance->down();
        $migration = require database_path('migrations/2026_11_14_000001_protect_document_evidence.php');
        $migration->down();
        $actor = User::factory()->create();
        $patient = Patient::factory()->create();
        $key = 'tenants/'.$patient->tenant_id.'/patients/'.$patient->id.'/legacy';
        Storage::disk('clinical_documents')->put($key, 'actual legacy bytes');
        $id = DB::table('patient_documents')->insertGetId(['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'disk' => 'clinical_documents', 'storage_key' => $key, 'original_filename' => 'legacy.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 19, 'uploaded_by_user_id' => $actor->id]);
        $migration->up();
        $patientInsurance->up();
        $v = DB::table('document_versions')->where('document_id', $id)->first();
        $this->assertNull($v->content_sha256);
        $this->assertSame('legacy_unverified', $v->scan_status);
        $this->assertSame('actual legacy bytes', Storage::disk('clinical_documents')->get($key));
        try {
            $migration->down();
            $this->fail('Populated document protections cannot be rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve protected document history', $e->getMessage());
        }
        $this->assertDatabaseHas('document_versions', ['id' => $v->id]);
    }

    public function test_replacement_reauthorizes_after_a_concurrent_purpose_link(): void
    {
        [$linkActor, $patient, $d] = $this->fixture();
        $v = $d->versionRecord;
        $writer = User::factory()->create();
        $role = Role::create(['slug' => Role::DENTIST, 'name' => 'Dentist']);
        $writer->roles()->attach($role);
        $other = $this->other();
        $real = app(DocumentStorage::class);
        $mock = $this->mock(DocumentStorage::class);
        $mock->shouldReceive('diskName')->andReturn($real->diskName());
        $mock->shouldReceive('put')->once()->andReturnUsing(function ($key, $file) use ($real, $other, $d, $v, $patient, $linkActor): void {
            $real->put($key, $file);
            // Another authorized command pins the original after the initial source check.
            $other->exec("INSERT INTO document_evidence_links(tenant_id,version_id,patient_id,actor_id,command_key) VALUES($d->tenant_id,$v->id,$patient->id,$linkActor->id,'".Str::uuid()."')");
        });
        $mock->shouldReceive('delete')->once()->andReturnUsing(fn ($doc) => $real->delete($doc));
        try {
            app(TenantContext::class)->run(Tenant::findOrFail($d->tenant_id), fn () => app(\App\Services\InsuranceDocumentWorkflow::class)->clinicalVersion($patient, $d, [
                'file' => UploadedFile::fake()->createWithContent('revision.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"),
                'command_key' => (string) Str::uuid(), 'provenance' => 'Clinical correction', 'expected_version' => 1,
            ], $writer));
            $this->fail('The writer lost source-purpose access while storage was pending.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseCount('document_versions', 1);
            $this->assertDatabaseCount('document_evidence_links', 1);
            $this->assertCount(1, Storage::disk('clinical_documents')->allFiles());
        }
    }
}
