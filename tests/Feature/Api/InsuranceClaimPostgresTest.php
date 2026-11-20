<?php

namespace Tests\Feature\Api;

use App\Models\InsuranceClaimLine;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InsuranceClaimWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InsuranceClaimPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName(), 'Insurance claim tests must run against PostgreSQL.');
    }

    private function actor(array $permissions = ['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'insurance_claims.view', 'insurance_claims.prepare', 'insurance_claims.submit']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'claim-test-'.$user->id, 'name' => 'Synthetic claim role']);
        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission->id);
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

    private function draft(Patient $patient, int $association, array $overrides = []): array
    {
        return $this->postJson('/api/patients/'.$patient->id.'/insurance-claims', array_replace(['association_id' => $association], $overrides))
            ->assertCreated()->json('data.claim');
    }

    private function linePayload(int $version, array $overrides = []): array
    {
        return array_replace(['expected_version' => $version, 'treatment_description' => 'Synthetic examination',
            'service_date' => '2031-04-02', 'quantity' => 2, 'unit_amount' => '25.25', 'currency' => 'JMD'], $overrides);
    }

    public function test_draft_creation_optional_policy_exact_lines_readiness_and_controlled_editing(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims', [])->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $claim = $this->draft($patient, $association);
        $claimPath = '/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'];
        $this->assertSame('draft', $claim['status']);
        $this->assertNull($claim['policy_revision_id']);
        $this->postJson($claimPath.'/lines', $this->linePayload(1))->assertCreated()
            ->assertJsonPath('data.line.unit_amount', '25.25')->assertJsonPath('data.line.gross_amount', '50.50')
            ->assertJsonPath('data.claim.lock_version', 2);
        $this->postJson($claimPath.'/lines', $this->linePayload(2, ['currency' => 'USD']))->assertCreated()
            ->assertJsonPath('data.claim.lock_version', 3);
        $this->postJson($claimPath.'/readiness', ['expected_version' => 3])->assertOk()
            ->assertJsonPath('data.ready', false)->assertJsonPath('data.reason_codes.0', 'CLAIM_LINES_CURRENCY_MISMATCH')
            ->assertJsonPath('data.claim.status', 'draft')->assertJsonPath('data.claim.lock_version', 3);
        $lineIds = InsuranceClaimLine::query()->where('claim_id', $claim['id'])->orderBy('id')->pluck('id');
        $this->patchJson($claimPath.'/lines/'.$lineIds[1], $this->linePayload(3, ['currency' => 'JMD']))->assertOk()
            ->assertJsonPath('data.claim.lock_version', 4);
        $this->postJson($claimPath.'/readiness', ['expected_version' => 4])->assertOk()
            ->assertJsonPath('data.ready', true)->assertJsonPath('data.claim.status', 'ready')
            ->assertJsonPath('data.claim.lock_version', 5);
        $this->patchJson($claimPath.'/lines/'.$lineIds[0], $this->linePayload(5, ['unit_amount' => '30.00']))->assertOk()
            ->assertJsonPath('data.claim.status', 'draft')->assertJsonPath('data.claim.lock_version', 6)
            ->assertJsonPath('data.line.gross_amount', '60.00');
        $this->postJson($claimPath.'/readiness', ['expected_version' => 5])->assertStatus(409);
        $this->postJson($claimPath.'/readiness', ['expected_version' => 6])->assertOk()->assertJsonPath('data.ready', true);
        $this->assertDatabaseHas('insurance_claim_events', ['claim_id' => $claim['id'], 'event_type' => 'draft_updated', 'from_status' => 'ready', 'to_status' => 'draft']);
    }

    public function test_submission_snapshot_is_immutable_idempotent_and_never_mutates_billing(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'];
        $this->postJson($path.'/lines', $this->linePayload(1, ['quantity' => 1, 'unit_amount' => '100.01']))->assertCreated();
        $this->postJson($path.'/readiness', ['expected_version' => 2])->assertOk()->assertJsonPath('data.claim.status', 'ready');
        $invoice = Invoice::factory()->for($patient)->issued()->withPayment('25.00')->create();
        $invoiceBefore = $invoice->fresh()->only(['amount_due', 'amount_paid', 'status', 'currency']);
        $invoiceItemsBefore = DB::table('invoice_items')->where('invoice_id', $invoice->id)->get()->toArray();
        $paymentsBefore = DB::table('payments')->where('invoice_id', $invoice->id)->get()->toArray();
        $before = collect(['invoices', 'invoice_items', 'payments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $request = ['expected_version' => 3, 'channel' => 'paper', 'channel_metadata' => ['method_note' => 'Synthetic staff note']];
        $headers = ['Idempotency-Key' => 'synthetic-claim-submit-001'];
        $response = $this->postJson($path.'/submissions', $request, $headers)->assertCreated()
            ->assertJsonPath('data.claim.status', 'submitted')->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.submission.sequence', 1)
            ->assertJsonPath('data.submission.snapshot.lines.0.gross_amount', '100.01');
        $submissionId = $response->json('data.submission.id');
        $this->postJson($path.'/submissions', $request, $headers)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($path.'/submissions', [...$request, 'channel' => 'email'], $headers)->assertStatus(409);
        $this->patchJson($path, ['expected_version' => 4])->assertStatus(409);
        $this->postJson($path.'/lines', $this->linePayload(4))->assertStatus(409);
        $this->postJson($path.'/cancel', ['expected_version' => 4, 'reason' => 'Synthetic cancellation'])->assertStatus(409);
        try {
            DB::transaction(fn () => DB::table('insurance_claim_submissions')->where('id', $submissionId)->update(['channel' => 'email']));
            $this->fail('Submitted snapshots must reject updates.');
        } catch (QueryException) {
            $this->assertDatabaseHas('insurance_claim_submissions', ['id' => $submissionId, 'channel' => 'paper']);
        }
        $lineId = DB::table('insurance_claim_lines')->where('claim_id', $claim['id'])->value('id');
        try {
            DB::transaction(fn () => DB::table('insurance_claim_lines')->where('id', $lineId)->update(['unit_amount' => '1.00']));
            $this->fail('Submitted claim lines must reject direct mutation.');
        } catch (QueryException) {
            $this->assertDatabaseHas('insurance_claim_lines', ['id' => $lineId, 'unit_amount' => '100.01']);
        }
        $this->assertSame($before, collect($before)->mapWithKeys(fn ($count, $table) => [$table => DB::table($table)->count()])->all());
        $this->assertSame($invoiceBefore, $invoice->fresh()->only(['amount_due', 'amount_paid', 'status', 'currency']));
        $this->assertEquals($invoiceItemsBefore, DB::table('invoice_items')->where('invoice_id', $invoice->id)->get()->toArray());
        $this->assertEquals($paymentsBefore, DB::table('payments')->where('invoice_id', $invoice->id)->get()->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => 'insurance_claim_submission_recorded', 'actor_id' => $actor->id]);
        $this->assertDatabaseHas('insurance_claim_events', ['claim_id' => $claim['id'], 'event_type' => 'submitted', 'from_status' => 'ready', 'to_status' => 'submitted']);
        $this->assertSame(1, DB::table('insurance_claim_submissions')->where('claim_id', $claim['id'])->count());
    }

    public function test_unknown_benefits_or_policy_details_are_not_required_and_direct_service_needs_exact_claim_permission(): void
    {
        $this->actor(['patients.view', 'patient_insurance.view', 'patient_insurance.manage', 'insurance_claims.view', 'insurance_claims.prepare']);
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'].'/lines', $this->linePayload(1))->assertCreated();
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'].'/readiness', ['expected_version' => 2])->assertOk()->assertJsonPath('data.ready', true);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'].'/submissions',
            ['expected_version' => 3, 'channel' => 'other'], ['Idempotency-Key' => 'synthetic-no-submit'])->assertForbidden();

        $wildcard = $this->actor(['patients.view', '*']);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims', ['association_id' => $association])->assertForbidden();
        $this->assertTrue($wildcard->hasPermission('*'));
        try {
            app(TenantContext::class)->run(Tenant::findOrFail($patient->tenant_id), fn () => app(InsuranceClaimWorkflow::class)
                ->create($patient, ['association_id' => $association], $wildcard));
            $this->fail('Direct workflow calls must enforce explicit claims permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_patient_and_tenant_scoping_reject_foreign_claim_and_line_references(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $foreignPatient = Patient::factory()->create();
        $association = $this->association($patient);
        $foreignAssociation = $this->association($foreignPatient);
        $claim = $this->draft($patient, $association);
        $this->getJson('/api/patients/'.$foreignPatient->id.'/insurance-claims/'.$claim['id'])->assertNotFound();
        $foreignTenant = \App\Models\Tenant::factory()->create();
        $actor->tenants()->attach($foreignTenant->id);
        $foreignResponse = $this->getJson('/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'], ['X-Tenant-Id' => (string) $foreignTenant->id]);
        $this->assertContains($foreignResponse->status(), [404, 422]);
        $foreignResponse->assertJsonPath('success', false)->assertJsonPath('data', null);
        $defaultTenantHeader = ['X-Tenant-Id' => (string) $patient->tenant_id];
        $foreignAssociationResponse = $this->postJson('/api/patients/'.$patient->id.'/insurance-claims', ['association_id' => $foreignAssociation], $defaultTenantHeader);
        $this->assertContains($foreignAssociationResponse->status(), [404, 422]);
        $foreignAssociationResponse->assertJsonPath('success', false)->assertJsonPath('data', null);
        $this->postJson('/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'].'/lines', $this->linePayload(1, ['treatment_plan_item_id' => 999999]), $defaultTenantHeader)
            ->assertNotFound();
        $this->assertDatabaseCount('insurance_claims', 1);
        $this->assertDatabaseCount('insurance_claim_lines', 0);
    }

    public function test_cancellation_and_populated_history_prohibit_rollback(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'];
        $this->postJson($path.'/cancel', ['expected_version' => 1, 'reason' => 'Synthetic cancellation'])->assertOk()
            ->assertJsonPath('data.claim.status', 'cancelled');
        $this->postJson($path.'/readiness', ['expected_version' => 1])->assertStatus(409);
        $migration = require database_path('migrations/2026_11_18_000001_create_insurance_claims.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_claim_foreign_keys_and_database_history_triggers_reject_direct_mutation(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        try {
            DB::transaction(fn () => DB::table('insurance_claims')->where('id', $claim['id'])->update(['status' => 'submitted', 'lock_version' => 2]));
            $this->fail('A raw invalid transition must fail.');
        } catch (QueryException) {
            $this->assertDatabaseHas('insurance_claims', ['id' => $claim['id'], 'status' => 'draft', 'lock_version' => 1]);
        }
        try {
            DB::transaction(fn () => DB::table('insurance_claim_events')->where('claim_id', $claim['id'])->update(['reason' => 'rewritten']));
            $this->fail('Lifecycle events must reject direct mutation.');
        } catch (QueryException) {
            $this->assertDatabaseHas('insurance_claim_events', ['claim_id' => $claim['id'], 'event_type' => 'created', 'reason' => null]);
        }
        $this->assertDatabaseHas('insurance_claim_events', ['claim_id' => $claim['id'], 'event_type' => 'created', 'version' => 1]);
    }

    public function test_independent_postgres_connection_observes_row_lock_conflict(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        DB::connection()->commit();
        DB::connection()->beginTransaction();
        DB::table('insurance_claims')->where('id', $claim['id'])->lockForUpdate()->first();

        $connection = Config::get('database.connections.pgsql');
        $pdo = new \PDO(
            'pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'],
            $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        try {
            $statement = $pdo->prepare('SELECT id FROM insurance_claims WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE NOWAIT');
            $statement->execute(['tenant_id' => $patient->tenant_id, 'id' => $claim['id']]);
            $this->fail('A separate session must not acquire a claim locked by the current claim-write transaction.');
        } catch (\PDOException $exception) {
            $this->assertSame('55P03', (string) $exception->getCode());
        } finally {
            $pdo = null;
            DB::connection()->rollBack();
        }
    }

    public function test_competing_workflow_submissions_create_exactly_one_snapshot_and_event(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $association = $this->association($patient);
        $claim = $this->draft($patient, $association);
        $path = '/api/patients/'.$patient->id.'/insurance-claims/'.$claim['id'];
        $this->postJson($path.'/lines', $this->linePayload(1, ['quantity' => 1]))->assertCreated();
        $this->postJson($path.'/readiness', ['expected_version' => 2])->assertOk()->assertJsonPath('data.claim.status', 'ready');

        $connection = Config::get('database.connections.pgsql');
        $pdo = new \PDO(
            'pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'],
            $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $barrier = new \PDO(
            'pgsql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'],
            $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $barrierKey = 23194;
        $processes = [];
        $pdo->exec("CREATE OR REPLACE FUNCTION test_claim_submission_barrier() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM pg_advisory_xact_lock({$barrierKey}, 7); RETURN NEW; END \$\$");
        $pdo->exec('CREATE TRIGGER test_claim_submission_barrier BEFORE INSERT ON insurance_claim_submissions FOR EACH ROW EXECUTE FUNCTION test_claim_submission_barrier()');
        $pdo->exec('SELECT pg_advisory_lock('.$barrierKey.', 7)');
        $startedAt = microtime(true);

        try {
            DB::connection()->commit();
            DB::connection()->beginTransaction();
            $base = [
                'tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'claim_id' => $claim['id'],
                'actor_id' => $actor->id, 'expected_version' => 3,
            ];
            $first = $this->submissionWorker($base + ['key' => 'competing-submit-a', 'channel' => 'paper', 'application' => 'claim_submit_a']);
            $processes[] = $first;
            $first->start();
            $this->waitForWorkerLock($pdo, 'claim_submit_a', 'insurance_claim_submissions');

            $second = $this->submissionWorker($base + ['key' => 'competing-submit-b', 'channel' => 'email', 'application' => 'claim_submit_b']);
            $processes[] = $second;
            $second->start();
            $this->waitForWorkerLock($pdo, 'claim_submit_b', 'insurance_claims');

            $pdo->exec('SELECT pg_advisory_unlock('.$barrierKey.', 7)');
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().' '.$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().' '.$second->getOutput());
            $results = [json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR), json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
            $this->assertCount(1, array_filter($results, fn ($result) => in_array($result['http_status'], [200, 201], true)));
            $this->assertCount(1, array_filter($results, fn ($result) => $result['http_status'] === 409));
            $this->assertSame(1, DB::table('insurance_claim_submissions')->where('claim_id', $claim['id'])->count());
            $this->assertSame([1], DB::table('insurance_claim_submissions')->where('claim_id', $claim['id'])->pluck('sequence')->all());
            $this->assertSame(1, DB::table('insurance_claim_events')->where('claim_id', $claim['id'])->where('event_type', 'submitted')->count());
            $this->assertDatabaseHas('insurance_claims', ['id' => $claim['id'], 'status' => 'submitted', 'lock_version' => 4]);
            $this->assertLessThan(10, microtime(true) - $startedAt, 'Competing submission requests must complete within the bounded synchronization window.');
        } finally {
            $pdo->exec('SELECT pg_advisory_unlock('.$barrierKey.', 7)');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::connection()->rollBack();
            $pdo->exec('DROP TRIGGER IF EXISTS test_claim_submission_barrier ON insurance_claim_submissions');
            $pdo->exec('DROP FUNCTION IF EXISTS test_claim_submission_barrier()');
            $barrier = null;
            $pdo = null;
        }
    }

    private function submissionWorker(array $input): Process
    {
        $php = PHP_BINARY;
        $extensionDir = ini_get('extension_dir');
        $worker = base_path('tests/Support/insurance_claim_submission_worker.php');

        return new Process([$php, '-d', 'extension_dir='.$extensionDir, '-d', 'extension=pdo_pgsql', '-d', 'extension=pgsql', $worker], base_path(), [
            'CLAIM_SUBMISSION_WORKER' => base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'), 'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ], null, null, 12);
    }

    private function waitForWorkerLock(\PDO $pdo, string $application, string $queryFragment): void
    {
        $deadline = microtime(true) + 8;
        do {
            $statement = $pdo->prepare('SELECT wait_event_type, query FROM pg_stat_activity WHERE application_name = :application');
            $statement->execute(['application' => $application]);
            $activity = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($activity !== false && $activity['wait_event_type'] === 'Lock' && str_contains($activity['query'], $queryFragment)) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Worker '.$application.' did not reach the expected database lock within the bounded synchronization window.');
    }
}
