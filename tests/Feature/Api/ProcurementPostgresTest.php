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

class ProcurementPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/procurement';

    private const GRANTS = ['suppliers.view', 'suppliers.manage', 'purchase_orders.view', 'purchase_orders.manage'];

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database.');
    }

    public function test_supplier_directory_and_versioned_draft_lifecycle(): void
    {
        $actor = $this->actor();
        $supplier = $this->supplier();
        $item = $this->item();
        $header = $this->key();
        $payload = $this->draft($supplier, $item);
        $order = $this->postJson(self::ROOT.'/purchase-orders', $payload, $header)->assertCreated()
            ->assertJsonPath('data.order.status', 'draft')->assertJsonPath('data.order.version', 1)
            ->assertJsonPath('data.order.lines.0.quantity', '0.125000')->json('data.order.id');
        $path = self::ROOT."/purchase-orders/$order";
        $this->getJson($path)->assertOk()->assertJsonPath('data.created_by_user_id', $actor->id)
            ->assertJsonMissingPath('data.request_hash');
        $this->getJson(self::ROOT."/purchase-orders?supplier_id=$supplier")->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonMissingPath('data.0.lines');
        $this->putJson($path, ['version' => 1, 'notes' => 'Recount', 'lines' => [['item_id' => $item, 'quantity' => '4']]])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.lines.0.quantity', '4.000000');
        $this->putJson($path, ['version' => 1, 'lines' => $payload['lines']])->assertConflict();
        $this->patchJson(self::ROOT."/suppliers/$supplier", ['active' => false, 'email' => null])->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.email', null);
        $this->getJson(self::ROOT.'/suppliers')->assertOk()->assertJsonPath('data.0.id', $supplier);
        $this->postJson(self::ROOT.'/purchase-orders', $payload, $this->key())->assertConflict();
        $this->postJson(self::ROOT.'/purchase-orders', $payload, $header)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.order.version', 2);
        $this->postJson(self::ROOT.'/purchase-orders', [...$payload, 'notes' => 'Changed'], $header)->assertConflict();
        $this->postJson($path.'/approve')->assertNotFound();
        $this->assertDatabaseCount('procurement_orders', 1);
        $this->assertDatabaseCount('procurement_order_lines', 1);
        $this->assertSame(2, DB::table('audit_logs')->where('resource_type', 'procurement_orders')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'procurement.supplier_updated', 'actor_id' => $actor->id]);
        foreach (['inventory_movements', 'invoices', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_invalid_payloads_and_failed_audit_leave_drafts_unchanged(): void
    {
        $this->actor();
        $supplier = $this->supplier();
        $item = $this->item();
        $this->postJson(self::ROOT.'/suppliers', ['code' => 'SUPPLIER', 'name' => 'Duplicate'])->assertConflict();
        foreach (['0', '-0.5', '0.0000001', '1e1', 1] as $quantity) {
            $this->postJson(self::ROOT.'/purchase-orders', $this->draft($supplier, $item, $quantity), $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        }
        $payload = $this->draft($supplier, $item);
        $this->postJson(self::ROOT.'/purchase-orders', [...$payload, 'status' => 'approved'], $this->key())->assertUnprocessable();
        $this->postJson(self::ROOT.'/purchase-orders', [...$payload, 'lines' => [...$payload['lines'], ...$payload['lines']]], $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.item_id');
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        app()->forgetInstance(\App\Services\ProcurementWorkflow::class);
        app('router')->getRoutes()->match(\Illuminate\Http\Request::create(self::ROOT.'/purchase-orders', 'POST'))->flushController();
        $this->postJson(self::ROOT.'/purchase-orders', $payload, $this->key())->assertInternalServerError();
        $this->assertDatabaseCount('procurement_orders', 0);
        $this->assertDatabaseCount('procurement_order_lines', 0);
    }

    public function test_exact_permissions_and_foreign_clinic_references(): void
    {
        $this->getJson(self::ROOT.'/suppliers')->assertUnauthorized();
        $this->actor(['suppliers.*', 'purchase_orders.*', '*']);
        $this->getJson(self::ROOT.'/suppliers')->assertForbidden();
        $this->postJson(self::ROOT.'/purchase-orders', [], $this->key())->assertForbidden();
        $this->actor();
        $supplier = $this->supplier();
        $item = $this->item();
        $order = $this->postJson(self::ROOT.'/purchase-orders', $this->draft($supplier, $item), $this->key())
            ->assertCreated()->json('data.order.id');
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->run($tenant, function () use ($tenant, $supplier, $item, $order) {
            $this->actor();
            $header = ['X-Tenant-Id' => (string) $tenant->id];
            $ownSupplier = $this->supplier($header);
            $ownItem = $this->item();
            $this->getJson(self::ROOT."/purchase-orders/$order", $header)->assertNotFound();
            $this->patchJson(self::ROOT."/suppliers/$supplier", ['name' => 'Foreign'], $header)->assertNotFound();
            $this->postJson(self::ROOT.'/purchase-orders', $this->draft($supplier, $ownItem), $header + $this->key())->assertNotFound();
            $this->postJson(self::ROOT.'/purchase-orders', $this->draft($ownSupplier, $item), $header + $this->key())->assertNotFound();
            $ownOrder = $this->postJson(self::ROOT.'/purchase-orders', $this->draft($ownSupplier, $ownItem), $header + $this->key())
                ->assertCreated()->json('data.order.id');
            try {
                DB::transaction(fn () => DB::table('procurement_order_lines')->insert([
                    'tenant_id' => $tenant->id, 'order_id' => $ownOrder, 'item_id' => $item, 'quantity' => '1',
                ]));
                $this->fail('Cross-clinic item insert should fail.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', (string) $exception->getCode());
            }
        });
    }

    private function actor(array $grants = self::GRANTS): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'procurement-test-'.$user->id, 'name' => 'Procurement test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function supplier(array $headers = []): int
    {
        return $this->postJson(self::ROOT.'/suppliers', [
            'code' => 'SUPPLIER', 'name' => 'Example Supply', 'email' => 'orders@example.test',
        ], $headers)->assertCreated()->json('data.id');
    }

    private function item(): int
    {
        return DB::table('inventory_items')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'sku' => 'GLOVES', 'name' => 'Gloves', 'base_unit' => 'each',
        ]);
    }

    private function draft(int $supplier, int $item, int|string $quantity = '0.125'): array
    {
        return ['supplier_id' => $supplier, 'lines' => [['item_id' => $item, 'quantity' => $quantity]]];
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
