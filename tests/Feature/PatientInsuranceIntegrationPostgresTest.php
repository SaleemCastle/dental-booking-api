<?php

namespace Tests\Feature;

use App\Documents\EvidenceScanner;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InsuranceMigrationRollback;
use Tests\TestCase;

/** Real combined migrations on a disposable PostgreSQL database; never production data. */
class PatientInsuranceIntegrationPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('Combined insurance integration requires PostgreSQL.');
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
    }

    private function actor(): array
    {
        $actor = User::factory()->create();
        $role = Role::create(['slug' => Role::ADMIN, 'name' => 'Admin']);
        $actor->roles()->attach($role);
        $this->seed(PermissionSeeder::class);
        Sanctum::actingAs($actor);

        return [$actor, $role, Patient::factory()->create()];
    }

    private function grant(Role $role, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $role->permissions()->syncWithoutDetaching([Permission::where('slug', $slug)->sole()->id]);
        }
    }

    private function document(Patient $patient): int
    {
        return $this->postJson('/api/patients/'.$patient->id.'/documents', [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"),
        ])->assertCreated()->json('data.document.id');
    }

    public function test_combined_empty_recreation_reuses_the_evidence_key_and_preserves_patient_rows(): void
    {
        [, , $patient] = $this->actor();
        $before = DB::table('patient')->where('id', $patient->id)->first();
        $b = require database_path('migrations/2026_11_15_000001_create_patient_insurance.php');
        $c = require database_path('migrations/2026_11_14_000001_protect_document_evidence.php');
        DB::unprepared("CREATE FUNCTION insurance_review_sentinel() RETURNS integer LANGUAGE sql AS 'SELECT 42'");
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $downstream = InsuranceMigrationRollback::rollbackAfter('2026_11_15_000001_create_patient_insurance.php');
            $this->assertSame('evidence_patient_tenant_unique', DB::selectOne(<<<'SQL'
SELECT key.conname FROM pg_constraint fk
JOIN pg_constraint key ON key.conindid=fk.conindid AND key.contype='u'
WHERE fk.conrelid='patient_insurer_associations'::regclass
AND fk.contype='f' AND fk.confrelid='patient'::regclass
SQL)->conname);
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname='patient_insurance_patient_tenant_unique'")->n);
            $b->down();
            $this->assertNotNull(DB::selectOne("SELECT to_regclass('document_versions') AS t")->t);
            $this->assertSame('evidence_patient_tenant_unique', DB::selectOne("SELECT conname FROM pg_constraint WHERE conname='evidence_patient_tenant_unique'")->conname);
            $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc p JOIN pg_namespace ns ON ns.oid=p.pronamespace WHERE ns.nspname=current_schema() AND p.proname LIKE 'patient_insurance_%'")->n);
            $c->down();
            $this->assertSame(42, DB::selectOne('SELECT insurance_review_sentinel() AS n')->n);
            $c->up();
            $b->up();
            InsuranceMigrationRollback::restore($downstream);
            $this->assertEquals($before, DB::table('patient')->where('id', $patient->id)->first());
            $this->assertDatabaseCount('patient_insurer_associations', 0);
            $this->assertDatabaseCount('patient_insurance_policies', 0);
            $this->assertFalse((bool) DB::selectOne('SELECT evidence_retained(NULL) AS n')->n);
        }
        DB::unprepared('DROP FUNCTION insurance_review_sentinel()');
    }

    public function test_capabilities_remain_separate_and_both_evidence_activation_gates_stay_closed(): void
    {
        [, $role, $patient] = $this->actor();
        $path = '/api/patients/'.$patient->id.'/insurance-associations';
        $document = $this->document($patient);
        $this->assertSame(3, Permission::where('resource', 'patient_insurance')->count());
        $this->assertSame(5, Permission::where('resource', 'insurance_documents')->count());
        $this->assertSame(1, Permission::where('resource', 'document_holds')->count());
        $this->assertFalse($role->permissions()->whereIn('permissions.resource', ['patient_insurance', 'insurance_documents', 'document_holds'])->exists());
        $this->assertFalse(config('insurance_evidence.uploads_enabled'));
        $this->assertFalse(config('insurance_evidence.linking_enabled'));
        $this->postJson($path, ['unverified_insurer_name' => 'Canopy'])->assertForbidden();
        $this->postJson('/api/document-evidence/'.$document.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Synthetic hold'])->assertForbidden();
        $this->grant($role, ['patient_insurance.view', 'patient_insurance.manage', 'patient_insurance.reveal']);
        $this->postJson($path, ['unverified_insurer_name' => 'Canopy'])->assertCreated();
        $this->postJson('/api/document-evidence/'.$document.'/holds', ['command_key' => (string) Str::uuid(), 'reason' => 'Synthetic hold'])->assertForbidden();
        $role->permissions()->detach(Permission::where('resource', 'patient_insurance')->pluck('id'));
        $this->grant($role, ['insurance_documents.view', 'insurance_documents.upload', 'insurance_documents.link', 'document_holds.manage']);
        $this->getJson($path)->assertForbidden();
        $this->postJson('/api/patients/'.$patient->id.'/insurance-documents', [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\n%%EOF\n"),
            'command_key' => (string) Str::uuid(), 'provenance' => 'Synthetic source',
        ])->assertConflict()->assertJsonPath('code', 'DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        $this->postJson('/api/document-evidence/'.$document.'/links', [
            'command_key' => (string) Str::uuid(), 'patient_id' => $patient->id,
        ])->assertConflict()->assertJsonPath('code', 'DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        $this->postJson('/api/document-evidence/'.$document.'/holds', [
            'command_key' => (string) Str::uuid(), 'reason' => 'Synthetic hold',
        ])->assertCreated();
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$document)
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        $this->assertDatabaseCount('patient_insurance_policies', 0);
    }

    public function test_sensitive_exception_redaction_survives_for_both_patient_insurance_and_evidence_routes(): void
    {
        [, $role, $patient] = $this->actor();
        $document = $this->document($patient);
        $this->grant($role, ['patient_insurance.view', 'patient_insurance.manage']);
        $private = 'SYNTHETIC-SECRET-MEMBER-9876';
        Log::spy();
        $this->mock(AuditLogService::class)->shouldReceive('record')->twice()->andThrow(
            new QueryException('pgsql', 'INSERT INTO synthetic VALUES(?)', [$private], new \RuntimeException($private))
        );
        foreach ([
            ['POST', '/api/patients/'.$patient->id.'/insurance-associations', ['unverified_insurer_name' => 'Canopy']],
            ['GET', '/api/patients/'.$patient->id.'/documents/'.$document, []],
        ] as [$method, $url, $input]) {
            $response = $this->json($method, $url, $input)->assertStatus(500);
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Unhandled API exception.'
            && isset($context['exception_class']) && ! isset($context['exception']) && ! str_contains(json_encode($context), $private))->twice();
        $this->assertDatabaseCount('patient_insurer_associations', 0);
        $this->assertDatabaseCount('patient_documents', 1);
        $property = new \ReflectionProperty(\App\Exceptions\Handler::class, 'dontFlash');
        $property->setAccessible(true);
        $neverFlash = $property->getValue(app(\App\Exceptions\Handler::class));
        foreach (['file', 'reason', 'provenance', 'policy_number', 'membership_id', 'subscriber_name', 'reference'] as $field) {
            $this->assertContains($field, $neverFlash);
        }
    }

    public function test_manual_active_observation_cannot_clear_quarantine_or_change_received_payments(): void
    {
        [$actor, $role, $patient] = $this->actor();
        $this->grant($role, ['patient_insurance.view', 'patient_insurance.manage']);
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'quarantined', 'reference' => 'synthetic-flagged-policy'];
            }
        });
        $document = $this->document($patient);
        $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
        $before = $invoice->fresh()->getAttributes();
        $path = '/api/patients/'.$patient->id.'/insurance-associations';
        $association = $this->postJson($path, ['unverified_insurer_name' => 'Sagicor'])->assertCreated()->json('data.association.id');
        $this->postJson($path.'/'.$association.'/checks', [
            'outcome' => 'active', 'channel' => 'external_card', 'checked_at' => now()->subMinute()->toISOString(),
            'provenance' => 'Staff recorded an external card-present result',
        ])->assertCreated()->assertJsonPath('data.check.source', 'staff_recorded_external')
            ->assertJsonPath('data.check.actor_id', $actor->id)->assertJsonPath('data.check.policy_revision_id', null);
        $this->getJson($path.'/'.$association)->assertOk()->assertJsonMissingPath('data.association.eligibility_status');
        $this->getJson('/api/patients/'.$patient->id.'/documents/'.$document.'/download')
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_QUARANTINED');
        $this->deleteJson('/api/patients/'.$patient->id.'/documents/'.$document)
            ->assertConflict()->assertJsonPath('code', 'DOCUMENT_RETENTION_BLOCKED');
        $this->assertDatabaseHas('document_protections', ['quarantined' => true, 'purge_state' => 'retained']);
        $this->assertDatabaseCount('patient_insurance_policies', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }
}
