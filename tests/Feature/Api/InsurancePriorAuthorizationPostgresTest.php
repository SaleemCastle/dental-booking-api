<?php

namespace Tests\Feature\Api;

use App\Documents\EvidenceScanner;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InsurancePriorAuthorizationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InsurancePriorAuthorizationPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName(), 'Prior authorization tests require PostgreSQL.');
    }

    private function actor(array $permissions = ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'insurance_claims.view', 'insurance_claims.prepare', 'insurance_prior_authorizations.view', 'insurance_prior_authorizations.manage', 'insurance_prior_authorizations.decide', 'insurance_prior_authorizations.evidence', 'insurance_documents.view']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'prior-auth-test-'.$user->id, 'name' => 'Prior authorization test']);
        foreach ($permissions as $slug) {
            [$resource,$action] = explode('.', $slug, 2);
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function association(Patient $patient): int
    {
        return $this->postJson('/api/patients/'.$patient->id.'/insurance-associations', ['unverified_insurer_name' => 'Synthetic insurer'])->assertCreated()->json('data.association.id');
    }

    private function create(Patient $patient, int $association, array $extra = []): array
    {
        $body = array_replace(['association_id' => $association, 'request_date' => '2031-04-02', 'treatment_description' => 'Synthetic crown preparation', 'procedure_details' => ['tooth' => '18'], 'requested_amount' => '125.50', 'currency' => 'USD'], $extra);

        return $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())->postJson('/api/patients/'.$patient->id.'/insurance-prior-authorizations', $body)->assertCreated()->json('data.authorization');
    }

    public function test_manual_lifecycle_partial_approval_unknown_values_correction_and_expiry_are_append_only(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $auth = $this->create($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'];
        $this->postJson($path.'/decisions', ['expected_version' => 1, 'decision_type' => 'approved', 'communication_channel' => 'phone', 'decision_at' => '2024-04-03T10:00:00Z', 'source_provenance' => 'Synthetic phone call'])->assertStatus(409);
        $this->postJson($path.'/requests', ['expected_version' => 1, 'communication_channel' => 'portal', 'source_provenance' => 'Clinic staff submitted by portal'])->assertOk()
            ->assertJsonPath('data.authorization.status', 'requested')->assertJsonPath('data.authorization.events.1.communication_channel', 'portal');
        $this->postJson($path.'/decisions', ['expected_version' => 2, 'decision_type' => 'approved', 'communication_channel' => 'phone', 'decision_at' => '2024-04-03T10:00:00Z', 'source_provenance' => 'Synthetic currency mismatch', 'authorized_amount' => '50.00', 'currency' => 'CAD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson($path.'/decisions', ['expected_version' => 2, 'decision_type' => 'partially_approved', 'communication_channel' => 'phone', 'decision_at' => '2024-04-03T10:00:00Z', 'source_provenance' => 'Synthetic staff-entered observation', 'authorized_amount' => '75.00', 'currency' => 'USD', 'valid_from' => '2024-04-01', 'valid_through' => '2024-04-30'])
            ->assertOk()->assertJsonPath('data.authorization.status', 'partially_approved')->assertJsonPath('data.authorization.decisions.0.staff_entered', true);
        $first = $this->getJson($path)->assertOk()->json('data.authorization.decisions.0');
        $this->postJson($path.'/decisions', ['expected_version' => 3, 'decision_type' => 'approved', 'corrects_decision_id' => $first['id'], 'correction_reason' => 'Corrected transcription', 'communication_channel' => 'phone', 'decision_at' => '2024-04-04T10:00:00Z', 'source_provenance' => 'Synthetic correction observation', 'valid_through' => '2024-04-05'])
            ->assertOk()->assertJsonPath('data.authorization.status', 'approved')->assertJsonCount(2, 'data.authorization.decisions')->assertJsonPath('data.authorization.decisions.1.authorized_amount', null)->assertJsonPath('data.authorization.decisions.1.currency', null);
        $this->postJson($path.'/expire')->assertOk()->assertJsonPath('data.authorization.status', 'expired');
        $this->assertDatabaseCount('insurance_prior_authorization_events', 5);
        $this->assertDatabaseCount('insurance_prior_authorization_decisions', 2);
        $this->assertDatabaseHas('insurance_prior_authorization_decisions', ['id' => $first['id'], 'decision_type' => 'partially_approved', 'authorized_amount' => '75.00']);
        $this->assertDatabaseHas('insurance_prior_authorizations', ['id' => $auth['id'], 'requested_amount' => '125.50', 'currency' => 'USD']);
    }

    public function test_optional_policy_exact_scopes_duplicate_creation_and_claim_link_are_enforced(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['association_id' => $association, 'request_date' => '2031-04-02', 'treatment_description' => 'Synthetic exam', 'requested_amount' => '0.01', 'currency' => 'JMD'];
        $url = '/api/patients/'.$patient->id.'/insurance-prior-authorizations';
        $first = $this->withHeader('Idempotency-Key', $key)->postJson($url, $body)->assertCreated()->json('data.authorization');
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $body)->assertCreated()->assertJsonPath('data.authorization.id', $first['id']);
        $this->assertSame(1, DB::table('insurance_prior_authorizations')->where('patient_id', $patient->id)->count());
        $this->withHeader('Idempotency-Key', $key)->postJson($url, [...$body, 'requested_amount' => '1.00'])->assertStatus(409);
        $claim = $this->postJson('/api/patients/'.$patient->id.'/insurance-claims', ['association_id' => $association])->assertCreated()->json('data.claim');
        $this->postJson($url.'/'.$first['id'].'/claim', ['claim_id' => $claim['id']])->assertOk()->assertJsonPath('data.authorization.claim_id', $claim['id']);
        $this->postJson($url.'/'.$first['id'].'/claim', ['claim_id' => $claim['id'] + 999])->assertNotFound();
        $foreign = Patient::factory()->create();
        $this->getJson('/api/patients/'.$foreign->id.'/insurance-prior-authorizations/'.$first['id'])->assertNotFound();
        $this->assertDatabaseHas('insurance_prior_authorizations', ['id' => $first['id'], 'policy_revision_id' => null, 'estimate_id' => null]);
    }

    public function test_estimate_trigger_accepts_matching_association_and_rejects_mismatch(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $otherAssociation = $this->association($patient);
        $authorization = $this->create($patient, $association);
        $makeEstimate = fn (int $estimateAssociation): int => DB::table('insurance_estimates')->insertGetId([
            'tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'association_id' => $estimateAssociation, 'benefit_rule_id' => null, 'supersedes_id' => null,
            'service_date' => '2031-04-03', 'treatment_description' => 'Synthetic estimate lineage', 'gross_amount' => '25.00', 'currency' => 'USD', 'status' => 'unresolved',
            'insurer_amount' => null, 'patient_amount' => null, 'calculation' => '{}', 'created_by_user_id' => $actor->id,
        ]);
        $matchingEstimate = $makeEstimate($association);
        $mismatchedEstimate = $makeEstimate($otherAssociation);
        $appendDraftRevision = function (int $estimateId, int $version) use ($patient, $actor, $authorization): void {
            DB::table('insurance_prior_authorizations')->where('id', $authorization['id'])->update(['estimate_id' => $estimateId, 'lock_version' => $version, 'updated_by_user_id' => $actor->id]);
            DB::table('insurance_prior_authorization_events')->insert(['tenant_id' => $patient->tenant_id, 'authorization_id' => $authorization['id'], 'version' => $version,
                'from_status' => 'draft', 'to_status' => 'draft', 'event_type' => 'draft_updated', 'reason' => 'Synthetic direct database guard check', 'actor_id' => $actor->id]);
        };
        DB::transaction(fn () => $appendDraftRevision($matchingEstimate, 2));
        $this->assertDatabaseHas('insurance_prior_authorizations', ['id' => $authorization['id'], 'estimate_id' => $matchingEstimate, 'lock_version' => 2]);
        try {
            DB::transaction(fn () => $appendDraftRevision($mismatchedEstimate, 3));
            $this->fail('A same-patient estimate from another association must be rejected by PostgreSQL.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('insurance_prior_authorizations', ['id' => $authorization['id'], 'estimate_id' => $matchingEstimate, 'lock_version' => 2]);
        }
    }

    public function test_direct_workflow_requires_exact_permission_and_evidence_linking_remains_disabled(): void
    {
        $patient = Patient::factory()->create();
        $actor = $this->actor(['patients.view']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Tenancy\TenantContext::class)->run(Tenant::findOrFail($patient->tenant_id), fn () => app(InsurancePriorAuthorizationWorkflow::class)->listing($patient, $actor, []));
    }

    public function test_evidence_linking_feature_switch_stays_disabled(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $auth = $this->create($patient, $association);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'].'/evidence',
            ['document_id' => 1, 'target_type' => 'authorization', 'target_id' => $auth['id'], 'command_key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(409);
    }

    public function test_clean_version_link_is_immutable_audited_and_retained_through_quarantine(): void
    {
        Storage::fake('clinical_documents');
        config(['insurance_evidence.uploads_enabled' => true, 'insurance_evidence.linking_enabled' => true]);
        $this->app->instance(EvidenceScanner::class, new class implements EvidenceScanner
        {
            public function scan(UploadedFile $file): array
            {
                return ['status' => 'clean', 'reference' => 'synthetic approved scanner'];
            }
        });
        $this->actor(['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'insurance_claims.view', 'insurance_claims.prepare', 'insurance_prior_authorizations.view', 'insurance_prior_authorizations.manage', 'insurance_prior_authorizations.decide', 'insurance_prior_authorizations.evidence', 'insurance_documents.view', 'insurance_documents.upload', 'document_quarantine.manage']);
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $auth = $this->create($patient, $association);
        $upload = $this->postJson('/api/patients/'.$patient->id.'/insurance-documents', ['file' => UploadedFile::fake()->createWithContent('support.pdf', "%PDF-1.4\n%%EOF"), 'command_key' => (string) \Illuminate\Support\Str::uuid(), 'provenance' => 'Synthetic supporting record'])->assertCreated();
        $document = PatientDocument::findOrFail($upload->json('data.document.id'));
        $path = '/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'];
        $linked = $this->postJson($path.'/evidence', ['document_id' => $document->id, 'target_type' => 'authorization', 'target_id' => $auth['id'], 'command_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated()->json('data.evidence');
        $this->getJson($path.'/evidence')->assertOk()->assertJsonCount(1, 'data.evidence')->assertJsonPath('data.evidence.0.version_id', $document->versionRecord->id);
        $this->assertTrue((bool) DB::selectOne('SELECT evidence_retained(?) AS retained', [$document->versionRecord->id])->retained);
        try {
            DB::transaction(fn () => DB::table('insurance_prior_authorization_evidence')->where('id', $linked['id'])->update(['actor_id' => 999999]));
            $this->fail('Evidence references must be immutable.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseHas('insurance_prior_authorization_evidence', ['id' => $linked['id'], 'version_id' => $document->versionRecord->id]);
        }
        try {
            (require database_path('migrations/2026_11_19_000001_create_prior_authorizations.php'))->down();
            $this->fail('Populated authorization evidence history must prevent rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Export prior authorization history', $error->getMessage());
        }
        $this->postJson('/api/document-evidence/'.$document->id.'/quarantine')->assertOk();
        $this->getJson($path.'/evidence')->assertConflict();
        $this->assertTrue((bool) DB::selectOne('SELECT evidence_retained(?) AS retained', [$document->versionRecord->id])->retained);
    }

    public function test_authorization_workflows_do_not_mutate_financial_tables(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $before = ['invoices' => DB::table('invoices')->count(), 'invoice_items' => DB::table('invoice_items')->count(), 'payments' => DB::table('payments')->count()];
        $auth = $this->create($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'];
        $this->postJson($path.'/requests', ['expected_version' => 1, 'communication_channel' => 'paper', 'source_provenance' => 'Clinic copy retained'])->assertOk();
        $this->postJson($path.'/decisions', ['expected_version' => 2, 'decision_type' => 'approved', 'communication_channel' => 'paper', 'decision_at' => '2031-04-03T10:00:00Z', 'source_provenance' => 'Staff-recorded approval'])->assertOk();
        $this->assertSame($before, ['invoices' => DB::table('invoices')->count(), 'invoice_items' => DB::table('invoice_items')->count(), 'payments' => DB::table('payments')->count()]);
    }

    public function test_expiry_requires_an_explicit_past_valid_through_date(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $auth = $this->create($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'];
        $this->postJson($path.'/requests', ['expected_version' => 1, 'communication_channel' => 'paper', 'source_provenance' => 'Clinic sent request'])->assertOk();
        $this->postJson($path.'/decisions', ['expected_version' => 2, 'decision_type' => 'approved', 'communication_channel' => 'paper', 'decision_at' => '2026-11-19T10:00:00Z', 'source_provenance' => 'Staff recorded approval'])->assertOk()
            ->assertJsonPath('data.authorization.decisions.0.valid_through', null)->assertJsonPath('data.authorization.decisions.0.authorized_amount', null);
        $this->postJson($path.'/expire')->assertStatus(409);
    }

    public function test_competing_decision_recordings_use_independent_postgres_connections(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $auth = $this->create($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-prior-authorizations/'.$auth['id'];
        $this->postJson($path.'/requests', ['expected_version' => 1, 'communication_channel' => 'portal', 'source_provenance' => 'Synthetic submitted request'])->assertOk();
        DB::connection()->commit();
        DB::connection()->beginTransaction();
        $connection = Config::get('database.connections.pgsql');
        $pdo = new \PDO('pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $barrierKey = 28741;
        $workers = [];
        $pdo->exec("CREATE OR REPLACE FUNCTION test_prior_auth_decision_barrier() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM pg_advisory_xact_lock({$barrierKey},9); RETURN NEW; END \$\$");
        $pdo->exec('CREATE TRIGGER test_prior_auth_decision_barrier BEFORE INSERT ON insurance_prior_authorization_decisions FOR EACH ROW EXECUTE FUNCTION test_prior_auth_decision_barrier()');
        $pdo->exec('SELECT pg_advisory_lock('.$barrierKey.',9)');
        try {
            $base = ['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'authorization_id' => $auth['id'], 'actor_id' => $actor->id, 'expected_version' => 2];
            $first = $this->decisionWorker($base + ['application' => 'prior_auth_a', 'decision_type' => 'approved']);
            $workers[] = $first;
            $first->start();
            $this->waitForWorkerLock($pdo, 'prior_auth_a', 'insurance_prior_authorization_decisions', $first);
            $second = $this->decisionWorker($base + ['application' => 'prior_auth_b', 'decision_type' => 'denied']);
            $workers[] = $second;
            $second->start();
            $this->waitForWorkerLock($pdo, 'prior_auth_b', 'insurance_prior_authorizations', $second);
            $pdo->exec('SELECT pg_advisory_unlock('.$barrierKey.',9)');
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().' '.$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().' '.$second->getOutput());
            $results = [json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR), json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
            $counts = array_count_values(array_map(fn ($r) => isset($r['http_status']) ? 0 : 1, $results));
            $this->assertSame(1, $counts[1] ?? 0);
            $this->assertSame(1, $counts[0] ?? 0);
            $this->assertSame(3, DB::table('insurance_prior_authorizations')->where('id', $auth['id'])->value('lock_version'));
            $this->assertSame(1, DB::table('insurance_prior_authorization_decisions')->where('authorization_id', $auth['id'])->count());
        } finally {
            $pdo->exec('SELECT pg_advisory_unlock('.$barrierKey.',9)');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            DB::connection()->rollBack();
            $pdo->exec('DROP TRIGGER IF EXISTS test_prior_auth_decision_barrier ON insurance_prior_authorization_decisions');
            $pdo->exec('DROP FUNCTION IF EXISTS test_prior_auth_decision_barrier()');
            $pdo = null;
        }
    }

    private function decisionWorker(array $input): Process
    {
        $env = ['PRIOR_AUTH_DECISION_WORKER' => base64_encode(json_encode($input, JSON_THROW_ON_ERROR)), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_USERNAME' => (string) config('database.connections.pgsql.username'), 'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'APP_KEY' => (string) config('app.key'), 'APP_ENV' => 'testing'];

        return new Process([PHP_BINARY, base_path('tests/Support/prior_authorization_decision_worker.php')], base_path(), $env, null, null, 60);
    }

    private function waitForWorkerLock(\PDO $pdo, string $application, string $fragment, Process $worker): void
    {
        $deadline = microtime(true) + 30;
        $lastRow = false;
        do {
            $stmt = $pdo->prepare('SELECT wait_event_type,wait_event,query FROM pg_stat_activity WHERE application_name=:application');
            $stmt->execute(['application' => $application]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $lastRow = $row;
            if ($row !== false && $row['wait_event_type'] === 'Lock' && str_contains($row['query'],$fragment)) {
                return;
            }usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker '.$application.' did not reach the expected PostgreSQL lock. Activity: '.json_encode($lastRow).' STDERR: '.$worker->getErrorOutput().' STDOUT: '.$worker->getOutput());
    }
}
