<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/inventory';

    private const GRANTS = ['inventory.view', 'inventory.manage_catalog', 'inventory.record_movements', 'inventory.correct_movements'];

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database for inventory tests.');
    }

    public function test_exact_movements_reconcile_and_corrections_append_once(): void
    {
        $actor = $this->actor();
        [$item, $location] = $this->catalog();
        $lot = $this->postJson(self::ROOT.'/lots', ['item_id' => $item, 'code' => 'LOT-01', 'expires_on' => '2030-07-01'])
            ->assertCreated()->assertJsonPath('data.expires_on', '2030-07-01')->json('data.id');
        $this->getJson(self::ROOT.'/items')->assertOk()->assertJsonPath('data.0.base_unit', 'each');
        $this->getJson(self::ROOT.'/locations')->assertOk()->assertJsonPath('data.0.id', $location);
        $this->getJson(self::ROOT."/lots?item_id=$item")->assertOk()->assertJsonPath('data.0.id', $lot);
        $headers = $this->key();
        $receipt = $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '0.3', $lot), $headers)
            ->assertCreated()->assertJsonPath('data.movement.quantity_delta', '0.300000')->json('data.movement.id');
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '0.300000', $lot), $headers)
            ->assertOk()->assertJsonPath('data.movement.id', $receipt)->assertJsonPath('data.replayed', true);
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '0.4', $lot), $headers)->assertConflict();
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'issue', '-0.1', $lot), $this->key())->assertCreated();
        $adjustment = $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'adjustment', '0.000001', $lot), $this->key())
            ->assertCreated()->json('data.movement.id');
        $this->postJson(self::ROOT."/movements/$receipt/corrections", ['reason' => 'Cannot reverse consumed receipt'], $this->key())->assertConflict();
        $correctionKey = $this->key();
        $correctionPath = self::ROOT."/movements/$adjustment/corrections";
        $correction = $this->postJson($correctionPath, ['reason' => 'Recount'], $correctionKey)
            ->assertCreated()->assertJsonPath('data.movement.quantity_delta', '-0.000001')
            ->assertJsonPath('data.movement.corrects_movement_id', $adjustment)->json('data.movement.id');
        $this->postJson($correctionPath, ['reason' => 'Recount'], $correctionKey)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($correctionPath, ['reason' => 'Recount'], $this->key())->assertConflict();
        $this->postJson(self::ROOT."/movements/$correction/corrections", ['reason' => 'Recount'], $this->key())->assertConflict();
        $this->getJson(self::ROOT."/balances?item_id=$item&lot_id=$lot")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.quantity', '0.200000');
        $this->getJson(self::ROOT."/movements?item_id=$item&per_page=2")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 4)
            ->assertJsonPath('data.0.recorded_by_user_id', $actor->id)
            ->assertJsonMissingPath('data.0.request_hash');
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.correction', 'actor_id' => $actor->id, 'resource_id' => (string) $correction]);
        $this->assertSame(4, DB::table('audit_logs')->where('resource_type', 'inventory_movements')->count());
        $this->assertDatabaseCount('treatments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_validates_catalog_quantity_and_bucket_boundaries(): void
    {
        $this->actor();
        [$item, $location] = $this->catalog();
        [$other, $otherLocation] = $this->catalog('OTHER');
        $this->postJson(self::ROOT.'/items', ['sku' => 'ITEM', 'name' => 'Duplicate', 'base_unit' => 'each'])->assertConflict();
        $this->postJson(self::ROOT.'/items', ['sku' => 'lowercase', 'name' => 'Bad code', 'base_unit' => 'each'])->assertUnprocessable();
        $this->postJson(self::ROOT.'/lots', ['item_id' => $item, 'code' => 'BAD-LOT', 'expires_on' => '2030-02-30'])->assertUnprocessable();
        $lot = $this->postJson(self::ROOT.'/lots', ['item_id' => $other, 'code' => 'LOT-01'])
            ->assertCreated()->assertJsonPath('data.expires_on', null)->json('data.id');
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '1', $lot), $this->key())->assertNotFound();
        foreach (['0', '-0.1', '1.1234567', '1e1', '1000000000000', 1] as $invalid) {
            $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', $invalid), $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors('quantity_delta');
        }
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '1'))->assertUnprocessable();
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '1'), $this->key())->assertCreated();
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $otherLocation, 'issue', '-1'), $this->key())->assertConflict();
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'issue', '-1'), $this->key())->assertCreated();
        $this->getJson(self::ROOT.'/balances')->assertOk()->assertJsonPath('data.0.quantity', '0.000000');
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_exact_permissions_and_tenant_qualified_links(): void
    {
        $this->getJson(self::ROOT.'/items')->assertUnauthorized();
        $this->actor(['inventory.*', '*']);
        $this->getJson(self::ROOT.'/items')->assertForbidden();
        $this->postJson(self::ROOT.'/items', [])->assertForbidden();
        $this->actor();
        [$item, $location] = $this->catalog();
        $original = $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '1'), $this->key())->assertCreated()->json('data.movement.id');
        $this->actor(['inventory.view', 'inventory.record_movements']);
        $this->postJson(self::ROOT."/movements/$original/corrections", ['reason' => 'No correction grant'], $this->key())->assertForbidden();

        $other = Tenant::factory()->create();
        app(TenantContext::class)->run($other, function () use ($other, $item, $location, $original) {
            $actor = $this->actor();
            $header = ['X-Tenant-Id' => (string) $other->id];
            $this->getJson(self::ROOT.'/movements', $header)->assertOk()->assertJsonStructure([
                'success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId',
            ])->assertJsonCount(0, 'data');
            [$otherItem, $otherLocation] = $this->catalog(headers: $header);
            $this->postJson(self::ROOT.'/movements', $this->movement($otherItem, $location, 'receipt', '1'), $header + $this->key())->assertNotFound();
            $this->postJson(self::ROOT.'/lots', ['item_id' => $item, 'code' => 'LOT-01'], $header)->assertNotFound();
            $this->postJson(self::ROOT."/movements/$original/corrections", ['reason' => 'Foreign source'], $header + $this->key())->assertNotFound();
            $this->rejectSql('23503', fn () => $this->insertMovement($other->id, $otherItem, $location, $actor->id, '1'));
            $this->rejectSql('23503', fn () => $this->insertMovement($other->id, $item, $otherLocation, $actor->id, '1'));
        });
    }

    public function test_permission_catalog_does_not_automatically_grant_inventory_access(): void
    {
        $admin = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Clinic Admin']);
        $this->seed(PermissionSeeder::class);
        $this->assertSame(self::GRANTS, Permission::where('resource', 'inventory')->orderBy('id')->pluck('slug')->all());
        $this->assertSame(0, $admin->permissions()->where('permissions.resource', 'inventory')->count());
    }

    public function test_expired_lots_reject_issues_but_allow_physical_adjustments(): void
    {
        $actor = $this->actor();
        [$item, $location] = $this->catalog();
        $dates = DB::selectOne("SELECT (timezone('UTC', clock_timestamp())::date - 1)::text AS expired,
            (timezone('UTC', clock_timestamp())::date + 1)::text AS current");
        $expired = $this->postJson(self::ROOT.'/lots', ['item_id' => $item, 'code' => 'EXPIRED', 'expires_on' => $dates->expired])
            ->assertCreated()->json('data.id');
        $current = $this->postJson(self::ROOT.'/lots', ['item_id' => $item, 'code' => 'CURRENT', 'expires_on' => $dates->current])
            ->assertCreated()->json('data.id');
        foreach ([$expired, $current] as $lot) {
            $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'receipt', '2', $lot), $this->key())->assertCreated();
        }

        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'issue', '-1', $expired), $this->key())
            ->assertConflict();
        $this->rejectSql('P5103', fn () => DB::table('inventory_movements')->insert([
            'tenant_id' => app(TenantContext::class)->id(), 'item_id' => $item, 'location_id' => $location,
            'lot_id' => $expired, 'kind' => 'issue', 'quantity_delta' => '-1',
            'recorded_by_user_id' => $actor->id, 'reason' => 'Expired lot direct insert',
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        ]));
        $this->getJson(self::ROOT."/balances?lot_id=$expired")->assertJsonPath('data.0.quantity', '2.000000');
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'issue', '-1', $current), $this->key())->assertCreated();
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'adjustment', '-1', $expired), $this->key())->assertCreated();
        $this->getJson(self::ROOT."/balances?lot_id=$expired")->assertJsonPath('data.0.quantity', '1.000000');
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertSame(4, DB::table('audit_logs')->where('resource_type', 'inventory_movements')->count());
    }

    public function test_database_history_and_precision_guards_and_audit_rollback(): void
    {
        $actor = $this->actor();
        [$item, $location] = $this->catalog();
        $tenantId = app(TenantContext::class)->id();
        $movement = $this->insertMovement($tenantId, $item, $location, $actor->id, '1.000000');
        $this->rejectSql('P5101', fn () => $this->insertMovement($tenantId, $item, $location, $actor->id, '-2'));
        $this->rejectSql('23514', fn () => $this->insertMovement($tenantId, $item, $location, $actor->id, '0.0000001'));
        $this->rejectSql('23514', fn () => DB::table('inventory_movements')->where('id', $movement)->update(['quantity_delta' => 2]));
        $this->rejectSql('23514', fn () => DB::table('inventory_movements')->where('id', $movement)->delete());
        $this->rejectSql('23514', fn () => DB::statement('TRUNCATE inventory_movements'));
        $this->rejectSql('23514', fn () => DB::table('inventory_items')->where('id', $item)->update(['base_unit' => 'box']));
        $this->rejectSql('P5102', fn () => DB::table('inventory_movements')->insert([
            'tenant_id' => $tenantId, 'item_id' => $item, 'location_id' => $location,
            'recorded_by_user_id' => $actor->id, 'kind' => 'correction', 'quantity_delta' => '-0.5',
            'corrects_movement_id' => $movement, 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'reason' => 'Incorrect compensating amount',
        ]));

        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->postJson(self::ROOT.'/movements', $this->movement($item, $location, 'issue', '-1'), $this->key())->assertInternalServerError();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $item, 'movement_version' => 1]);
        $this->getJson(self::ROOT.'/balances')->assertJsonPath('data.0.quantity', '1.000000');
    }

    public function test_independent_postgresql_sessions_cannot_both_issue_the_same_stock(): void
    {
        $actor = $this->actor();
        [$item, $location] = $this->catalog();
        $tenantId = app(TenantContext::class)->id();
        $this->insertMovement($tenantId, $item, $location, $actor->id, '1.000000');
        DB::commit();
        $worker = null;
        try {
            DB::beginTransaction();
            $this->insertMovement($tenantId, $item, $location, $actor->id, '-1.000000');
            $worker = new Process([PHP_BINARY, '-r', <<<'PHP'
                $pdo = new PDO(getenv('INVENTORY_TEST_DSN'), getenv('INVENTORY_TEST_USER'), getenv('INVENTORY_TEST_PASSWORD'));
                $pdo->exec("SET lock_timeout = '12s'");
                echo $pdo->query('SELECT pg_backend_pid()')->fetchColumn().PHP_EOL;
                $sql = "INSERT INTO inventory_movements (tenant_id, item_id, location_id, recorded_by_user_id, kind, quantity_delta, reason, idempotency_key, request_hash)
                        VALUES (?, ?, ?, ?, 'issue', -1, 'Concurrent issue', ?, repeat('a', 64))";
                try {
                    $pdo->prepare($sql)->execute(array_slice($argv, 1));
                    echo 'inserted';
                } catch (PDOException $exception) {
                    echo $exception->getCode();
                }
                PHP,
                (string) $tenantId, (string) $item, (string) $location, (string) $actor->id, (string) Str::uuid(),
            ], base_path(), $this->workerDatabase(), timeout: 18);
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => $type === Process::OUT && preg_match('/^\d+\s*$/', $output) === 1));
            $pid = (int) trim($worker->getOutput());
            $waiting = false;
            $deadline = microtime(true) + 7;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(20_000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second issue must wait for the first transaction.');
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertStringEndsWith('P5101', $worker->getOutput());
            $this->assertDatabaseCount('inventory_movements', 2);
            $this->getJson(self::ROOT.'/balances')->assertJsonPath('data.0.quantity', '0.000000');
        } finally {
            $worker?->stop();
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->artisan('migrate:fresh')->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function actor(array $grants = self::GRANTS): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'inventory-test-'.$user->id, 'name' => 'Inventory test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function catalog(string $code = 'ITEM', array $headers = []): array
    {
        $item = $this->postJson(self::ROOT.'/items', ['sku' => $code, 'name' => 'Stock item', 'base_unit' => 'each'], $headers)
            ->assertCreated()->json('data.id');
        $location = $this->postJson(self::ROOT.'/locations', ['code' => $code, 'name' => 'Stockroom'], $headers)
            ->assertCreated()->json('data.id');

        return [$item, $location];
    }

    private function movement(int $item, int $location, string $kind, string|int $quantity, ?int $lot = null): array
    {
        return ['item_id' => $item, 'location_id' => $location, 'kind' => $kind, 'quantity_delta' => $quantity, 'lot_id' => $lot, 'reason' => 'Manual count'];
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function insertMovement(int $tenant, int $item, int $location, int $actor, string $delta): int
    {
        return DB::table('inventory_movements')->insertGetId([
            'tenant_id' => $tenant, 'item_id' => $item, 'location_id' => $location,
            'recorded_by_user_id' => $actor, 'kind' => 'adjustment', 'quantity_delta' => $delta,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'reason' => 'Direct database check',
        ]);
    }

    private function rejectSql(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected PostgreSQL error $state");
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }

    private function workerDatabase(): array
    {
        $config = DB::connection()->getConfig();

        return [
            'INVENTORY_TEST_DSN' => 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
            'INVENTORY_TEST_USER' => $config['username'],
            'INVENTORY_TEST_PASSWORD' => $config['password'],
        ];
    }
}
