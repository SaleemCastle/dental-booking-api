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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SupplierInvoicePostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/procurement/supplier-invoices';

    private const TABLE = 'procurement_supplier_invoices';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database.');
    }

    public function test_records_reported_values_and_replays_without_financial_or_stock_mutation(): void
    {
        $actor = $this->actor();
        $supplier = $this->supplier();
        $receipt = $this->receipt($supplier);
        $payload = [...$this->observation($supplier), 'supplier_receipt_id' => $receipt, 'due_on' => '2026-12-01'];
        $key = $this->key();
        $inventory = (array) DB::table('inventory_movements')->sole();
        $id = $this->postJson(self::ROOT, $payload, $key)->assertCreated()
            ->assertJsonPath('data.invoice.reported_total', '9999999999999999.90')
            ->assertJsonPath('data.invoice.due_on', '2026-12-01')
            ->assertJsonPath('data.invoice.supplier_receipt_id', $receipt)
            ->assertJsonPath('data.invoice.recorded_by_user_id', $actor->id)->json('data.invoice.id');
        $this->postJson(self::ROOT, [...$payload, 'reported_total' => '9999999999999999.90'], $key)
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.invoice.id', $id);
        $this->postJson(self::ROOT, [...$payload, 'invoice_number' => 'vendor-01'], $this->key())->assertConflict();
        $this->postJson(self::ROOT, [...$payload, 'reported_total' => '1'], $key)->assertConflict();
        $this->getJson(self::ROOT.'/'.$id)->assertOk()->assertJsonPath('data.currency', 'JMD')->assertJsonMissingPath('data.request_hash');
        $this->getJson(self::ROOT.'?supplier_id='.$supplier)->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->assertSame($inventory, (array) DB::table('inventory_movements')->sole());
        foreach (['procurement_orders', 'invoices', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount(self::TABLE, 1);
        $this->assertSame(1, DB::table('audit_logs')->where('resource_type', self::TABLE)->count());
        $this->assertDatabaseHas('audit_logs', ['resource_id' => (string) $id, 'actor_id' => $actor->id, 'action' => 'procurement.supplier_invoice_recorded']);
    }

    public function test_permissions_supplier_lineage_and_clinic_boundaries(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->actor(['supplier_invoices.*', 'supplier_receipts.record']);
        $this->getJson(self::ROOT)->assertForbidden();
        $this->postJson(self::ROOT, [], $this->key())->assertForbidden();
        $this->actor();
        $supplier = $this->supplier();
        $receipt = $this->receipt($supplier);
        $otherSupplier = $this->supplier('OTHER');
        $this->postJson(self::ROOT, [...$this->observation($otherSupplier), 'supplier_receipt_id' => $receipt], $this->key())->assertNotFound();
        $id = $this->postJson(self::ROOT, $this->observation($supplier), $this->key())->assertCreated()
            ->assertJsonPath('data.invoice.supplier_receipt_id', null)->json('data.invoice.id');

        $otherClinic = Tenant::factory()->create();
        app(TenantContext::class)->run($otherClinic, function () use ($otherClinic, $supplier, $receipt, $id) {
            $this->actor();
            $headers = ['X-Tenant-Id' => (string) $otherClinic->id];
            $own = $this->supplier();
            $this->getJson(self::ROOT.'/'.$id, $headers)->assertNotFound();
            $this->getJson(self::ROOT, $headers)->assertOk()->assertJsonCount(0, 'data');
            $this->postJson(self::ROOT, $this->observation($supplier), $headers + $this->key())->assertNotFound();
            $this->postJson(self::ROOT, [...$this->observation($own), 'supplier_receipt_id' => $receipt], $headers + $this->key())->assertNotFound();
            $this->postJson(self::ROOT, $this->observation($own), $headers + $this->key())->assertCreated();
        });
    }

    public function test_database_guards_preserve_history_and_supplier_integrity(): void
    {
        $this->actor();
        $supplier = $this->supplier();
        $other = $this->supplier('OTHER');
        $receipt = $this->receipt($supplier);
        $id = $this->postJson(self::ROOT, $this->observation($supplier), $this->key())->assertCreated()->json('data.invoice.id');
        $row = (array) DB::table(self::TABLE)->where('id', $id)->first();
        unset($row['id']);
        $row = [...$row, 'invoice_number' => 'NEW', 'idempotency_key' => (string) Str::uuid()];
        $this->rejectWrite('23503', fn () => DB::table(self::TABLE)->insert([...$row, 'supplier_id' => $other, 'supplier_receipt_id' => $receipt]));
        $this->rejectWrite('23514', fn () => DB::table(self::TABLE)->insert([...$row, 'reported_total' => '0.001']));
        $this->rejectWrite('23514', fn () => DB::table(self::TABLE)->where('id', $id)->update(['reported_total' => '1']));
        $this->rejectWrite('23514', fn () => DB::table(self::TABLE)->where('id', $id)->delete());
        $this->rejectWrite('23514', fn () => DB::statement('TRUNCATE '.self::TABLE));
    }

    public function test_invalid_inputs_and_audit_failure_leave_no_invoice(): void
    {
        $this->actor();
        $payload = $this->observation($this->supplier());
        foreach (['-1', '1.001', '1e1', 1, '10000000000000000'] as $amount) {
            $this->postJson(self::ROOT, [...$payload, 'reported_total' => $amount], $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors('reported_total');
        }
        $this->postJson(self::ROOT, [...$payload, 'invoice_date' => '2026-02-30'], $this->key())->assertUnprocessable();
        $this->postJson(self::ROOT, [...$payload, 'payment_status' => 'paid'], $this->key())->assertUnprocessable();
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        app('router')->getRoutes()->match(\Illuminate\Http\Request::create(self::ROOT, 'POST'))->flushController();
        $this->postJson(self::ROOT, $payload, $this->key())->assertInternalServerError();
        $this->assertDatabaseCount(self::TABLE, 0);
    }

    private function rejectWrite(string $state, callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Expected PostgreSQL to reject the write.');
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }

    private function actor(array $grants = ['supplier_invoices.record', 'supplier_invoices.view', 'supplier_receipts.record']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'supplier-invoice-test-'.$user->id, 'name' => 'Supplier invoice test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function supplier(string $code = 'SUPPLIER'): int
    {
        return DB::table('procurement_suppliers')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'code' => $code, 'name' => 'Example supplier',
        ]);
    }

    private function receipt(int $supplier): int
    {
        $tenant = app(TenantContext::class)->id();
        $item = DB::table('inventory_items')->insertGetId(['tenant_id' => $tenant, 'sku' => 'GLOVES', 'name' => 'Gloves', 'base_unit' => 'each']);
        $location = DB::table('inventory_locations')->insertGetId(['tenant_id' => $tenant, 'code' => 'STOCK', 'name' => 'Stockroom']);

        return $this->postJson('/api/procurement/supplier-receipts', [
            'supplier_id' => $supplier, 'reference' => 'DELIVERY-01', 'received_on' => '2026-01-01',
            'lines' => [['item_id' => $item, 'location_id' => $location, 'quantity' => '1']],
        ], $this->key())->assertCreated()->json('data.receipt.id');
    }

    private function observation(int $supplier): array
    {
        return ['supplier_id' => $supplier, 'invoice_number' => 'Vendor-01', 'invoice_date' => '2026-11-01',
            'currency' => 'JMD', 'reported_total' => '9999999999999999.9'];
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
