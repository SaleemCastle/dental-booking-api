<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SupplierReceivingPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/procurement/supplier-receipts';

    private const GRANTS = ['supplier_receipts.record', 'supplier_receipts.view', 'inventory.view', 'inventory.record_movements'];

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database.');
    }

    public function test_receipt_records_exact_movements_and_reuses_existing_lot_expiry_rules(): void
    {
        $actor = $this->actor();
        $payload = $this->delivery();
        $key = $this->key();
        $response = $this->postJson(self::ROOT, $payload, $key)->assertCreated()
            ->assertJsonPath('data.receipt.recorded_by_user_id', $actor->id)
            ->assertJsonPath('data.receipt.lines.0.expires_on', '2020-05-01')
            ->assertJsonPath('data.receipt.lines.0.quantity', '0.125000');
        $receipt = $response->json('data.receipt.id');
        $movement = $response->json('data.receipt.lines.0.movement_id');
        $line = $payload['lines'][0];
        $this->getJson(self::ROOT.'/'.$receipt)->assertOk()->assertJsonPath('data.lines.0.movement_id', $movement)
            ->assertJsonMissingPath('data.request_hash');
        $this->getJson(self::ROOT.'?supplier_id='.$payload['supplier_id'])
            ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonMissingPath('data.0.lines');
        $this->getJson('/api/inventory/movements')->assertOk()->assertJsonPath('data.0.supplier_receipt_id', $receipt);
        $this->getJson('/api/inventory/balances')->assertOk()->assertJsonPath('data.0.quantity', '0.125000');
        $this->postJson('/api/inventory/movements', [
            'item_id' => $line['item_id'], 'location_id' => $line['location_id'], 'lot_id' => $line['lot_id'],
            'kind' => 'issue', 'quantity_delta' => '-0.125', 'reason' => 'Expired stock check',
        ], $this->key())->assertConflict();
        $payload['lines'][0]['quantity'] = '0.125000';
        $this->postJson(self::ROOT, $payload, $key)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson(self::ROOT, $payload, $this->key())->assertConflict();
        $this->postJson(self::ROOT, [...$payload, 'reference' => 'DIFFERENT'], $key)->assertConflict();
        $this->assertDatabaseCount('procurement_supplier_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('audit_logs', ['resource_id' => (string) $receipt, 'action' => 'procurement.supplier_receipt_recorded']);
        $this->assertDatabaseHas('audit_logs', ['resource_id' => (string) $movement, 'action' => 'inventory.receipt']);
        foreach (['procurement_orders', 'invoices', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_stock_references_and_receipt_permissions_are_clinic_scoped(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->actor(['supplier_receipts.*', 'inventory.record_movements']);
        $this->postJson(self::ROOT, [], $this->key())->assertForbidden();
        $this->getJson(self::ROOT)->assertForbidden();
        $this->actor();
        $foreign = $this->delivery();
        $foreignReceipt = $this->postJson(self::ROOT, $foreign, $this->key())->assertCreated()->json('data.receipt.id');
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->run($tenant, function () use ($tenant, $foreign, $foreignReceipt) {
            $this->actor(['supplier_receipts.record', 'supplier_receipts.view']);
            $headers = ['X-Tenant-Id' => (string) $tenant->id];
            $own = $this->delivery();
            $this->postJson(self::ROOT, [...$own, 'supplier_id' => $foreign['supplier_id']], $headers + $this->key())->assertNotFound();
            $this->postJson(self::ROOT, [...$own, 'lines' => $foreign['lines']], $headers + $this->key())->assertNotFound();
            $ownBadLot = $own;
            $ownBadLot['lines'][0]['lot_id'] = $foreign['lines'][0]['lot_id'];
            $this->postJson(self::ROOT, $ownBadLot, $headers + $this->key())->assertNotFound();
            $this->getJson(self::ROOT.'/'.$foreignReceipt, $headers)->assertNotFound();
            $this->getJson(self::ROOT, $headers)->assertOk()->assertJsonCount(0, 'data');
            $this->postJson(self::ROOT, $own, $headers + $this->key())->assertCreated();
        });
    }

    public function test_invalid_or_failed_receipts_leave_no_partial_stock_or_audit(): void
    {
        $this->actor();
        $payload = $this->delivery();
        $this->postJson(self::ROOT, [...$payload, 'purchase_order_id' => 1], $this->key())->assertUnprocessable();
        $this->postJson(self::ROOT, [...$payload, 'lines' => [...$payload['lines'], ...$payload['lines']]], $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1');
        $this->postJson(self::ROOT, [...$payload, 'lines' => [[...$payload['lines'][0], 'quantity' => '0']]], $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        DB::table('procurement_suppliers')->where('id', $payload['supplier_id'])->update(['active' => false]);
        $this->postJson(self::ROOT, $payload, $this->key())->assertConflict();
        DB::table('procurement_suppliers')->where('id', $payload['supplier_id'])->update(['active' => true]);
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        app('router')->getRoutes()->match(\Illuminate\Http\Request::create(self::ROOT, 'POST'))->flushController();
        $this->postJson(self::ROOT, $payload, $this->key())->assertInternalServerError();
        $this->assertDatabaseCount('procurement_supplier_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('resource_type', 'inventory_movements')->count());
    }

    public function test_postgresql_seals_headers_and_lines_after_commit(): void
    {
        $actor = $this->actor();
        $payload = $this->delivery();
        $id = $this->postJson(self::ROOT, $payload, $this->key())->assertCreated()->json('data.receipt.id');
        DB::commit();
        try {
            $this->rejectWrite(fn () => DB::table('procurement_supplier_receipts')->where('id', $id)->update(['reference' => 'CHANGED']));
            $this->rejectWrite(fn () => DB::table('procurement_supplier_receipts')->where('id', $id)->delete());
            $this->rejectWrite(fn () => DB::table('inventory_movements')->insert([
                'tenant_id' => app(TenantContext::class)->id(), 'supplier_receipt_id' => $id,
                'item_id' => $payload['lines'][0]['item_id'], 'location_id' => $payload['lines'][0]['location_id'],
                'kind' => 'receipt', 'quantity_delta' => '1', 'reason' => 'Late append',
                'recorded_by_user_id' => $actor->id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            ]));
            $this->rejectWrite(function () use ($actor, $payload) {
                DB::transaction(fn () => DB::table('procurement_supplier_receipts')->insert([
                    'tenant_id' => app(TenantContext::class)->id(), 'supplier_id' => $payload['supplier_id'],
                    'reference' => 'EMPTY', 'received_on' => '2020-05-02', 'recorded_by_user_id' => $actor->id,
                    'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
                ]));
            });
            $this->assertDatabaseCount('procurement_supplier_receipts', 1);
            $this->assertDatabaseCount('inventory_movements', 1);
        } finally {
            $this->artisan('migrate:fresh')->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function rejectWrite(callable $write): void
    {
        try {
            $write();
            $this->fail('Expected the database to reject the write.');
        } catch (QueryException|\PDOException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }

    private function actor(array $grants = self::GRANTS): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'receiving-test-'.$user->id, 'name' => 'Supplier receiving test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function delivery(): array
    {
        $tenant = app(TenantContext::class)->id();
        $supplier = DB::table('procurement_suppliers')->insertGetId(['tenant_id' => $tenant, 'code' => 'SUPPLIER', 'name' => 'Example Supplier']);
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $tenant, 'sku' => 'GLOVES', 'name' => 'Gloves', 'base_unit' => 'each']);
        $location = DB::table('inventory_locations')->insertGetId(['tenant_id' => $tenant, 'code' => 'STOCK', 'name' => 'Stockroom']);
        $lot = DB::table('inventory_lots')->insertGetId(['tenant_id' => $tenant, 'item_id' => $item, 'code' => 'LOT-01', 'expires_on' => '2020-05-01']);

        return ['supplier_id' => $supplier, 'reference' => 'DELIVERY-1', 'received_on' => '2020-05-02',
            'lines' => [['item_id' => $item, 'location_id' => $location, 'lot_id' => $lot, 'quantity' => '0.125']]];
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
