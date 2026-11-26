<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class InventoryReportsPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/inventory/reports';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database.');
    }

    public function test_low_stock_counts_usable_stock_and_catalog_items_without_movements(): void
    {
        $actor = $this->actor();
        $main = $this->location('MAIN');
        $backup = $this->location('BACKUP');
        $gloves = $this->item('GLOVES');
        $this->item('EMPTY');
        $dates = $this->dates();
        $this->movement($actor, $gloves, $main, '100', $this->lot($gloves, 'EXPIRED', $dates->yesterday));
        $this->movement($actor, $gloves, $main, '0.200001', $this->lot($gloves, 'TODAY', $dates->today));
        $this->movement($actor, $gloves, $main, '0.1');
        $this->movement($actor, $gloves, $backup, '2');

        $this->getJson(self::ROOT.'/low-stock?threshold=0.300001')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'EMPTY');
        $scope = '?location_id='.$main;
        $this->getJson(self::ROOT.'/low-stock'.$scope.'&threshold=0.300000')->assertOk()->assertJsonCount(1, 'data');
        $result = $this->getJson(self::ROOT.'/low-stock'.$scope.'&threshold=0.300001')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.1.sku', 'GLOVES')->assertJsonPath('data.1.usable_quantity', '0.300001')
            ->assertJsonPath('meta.criteria.threshold', '0.300001')->assertJsonPath('meta.criteria.location_id', $main)
            ->assertJsonPath('meta.criteria.as_of', $dates->today);
        $result->assertJsonPath('data.0.usable_quantity', '0.000000');
        $this->getJson(self::ROOT.'/low-stock'.$scope.'&threshold=0.300001&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.hasMorePages', true);
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'inventory_reports.low_stock_viewed')->count());
    }

    public function test_expiry_lists_positive_dated_lot_balances_per_location(): void
    {
        $actor = $this->actor();
        $location = $this->location('MAIN');
        $backup = $this->location('BACKUP');
        $item = $this->item('GLOVES');
        $dates = $this->dates();
        $expired = $this->lot($item, 'EXPIRED', $dates->yesterday);
        $today = $this->lot($item, 'TODAY', $dates->today);
        $soon = $this->lot($item, 'SOON', $dates->tomorrow);
        $this->movement($actor, $item, $location, '0.125', $expired);
        $this->movement($actor, $item, $location, '0.1', $today);
        $this->movement($actor, $item, $backup, '0.5', $today);
        $this->movement($actor, $item, $location, '0.7', $soon);
        $this->movement($actor, $item, $location, '-0.7', $soon);
        $this->movement($actor, $item, $location, '4', $this->lot($item, 'FUTURE', $dates->future));
        $this->movement($actor, $item, $location, '5', $this->lot($item, 'UNDATED', null));
        $this->movement($actor, $item, $location, '6');

        $this->getJson(self::ROOT.'/expiry?location_id='.$location.'&days=1')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.physical_quantity', '0.125000')->assertJsonPath('data.0.lot_code', 'EXPIRED')
            ->assertJsonPath('data.1.status', 'expiring')->assertJsonPath('data.1.expires_on', $dates->today)
            ->assertJsonPath('meta.criteria.through', $dates->tomorrow);
        $this->getJson(self::ROOT.'/expiry?days=0')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.location_id', $backup)->assertJsonPath('data.2.physical_quantity', '0.500000');
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory_reports.expiry_viewed', 'actor_id' => $actor->id,
            'resource_id' => (string) app(TenantContext::class)->id()]);
    }

    public function test_reports_require_exact_permission_and_never_return_foreign_clinic_stock(): void
    {
        $this->getJson(self::ROOT.'/low-stock')->assertUnauthorized();
        $this->actor(['inventory.view', 'inventory_reports.*', '*']);
        $this->getJson(self::ROOT.'/low-stock')->assertForbidden();
        $this->getJson(self::ROOT.'/expiry')->assertForbidden();
        $this->actor();
        $location = $this->location('MAIN');
        $this->item('PRIVATE');
        $this->getJson(self::ROOT.'/low-stock?threshold=1e3')->assertUnprocessable();
        $this->getJson(self::ROOT.'/expiry?days=366')->assertUnprocessable();
        $other = Tenant::factory()->create();
        app(TenantContext::class)->run($other, function () use ($other, $location) {
            $this->actor();
            $this->item('OTHER');
            $headers = ['X-Tenant-Id' => (string) $other->id];
            $this->getJson(self::ROOT.'/low-stock?location_id='.$location, $headers)->assertNotFound();
            $this->getJson(self::ROOT.'/low-stock', $headers)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.sku', 'OTHER');
        });
        $this->assertSame(1, DB::table('audit_logs')->where('resource_type', 'inventory_reports')->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $other->id, 'resource_type' => 'inventory_reports']);
    }

    public function test_audit_failure_does_not_return_report_data(): void
    {
        $this->actor();
        $this->item('PRIVATE');
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->getJson(self::ROOT.'/low-stock')->assertInternalServerError()->assertJsonPath('data', null);
    }

    private function actor(array $grants = ['inventory_reports.view']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'inventory-reports-'.$user->id, 'name' => 'Inventory reports test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function item(string $sku): int
    {
        return DB::table('inventory_items')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'sku' => $sku, 'name' => $sku, 'base_unit' => 'each',
        ]);
    }

    private function location(string $code): int
    {
        return DB::table('inventory_locations')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'code' => $code, 'name' => $code,
        ]);
    }

    private function lot(int $item, string $code, ?string $date): int
    {
        return DB::table('inventory_lots')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'item_id' => $item, 'code' => $code, 'expires_on' => $date,
        ]);
    }

    private function movement(User $actor, int $item, int $location, string $quantity, ?int $lot = null): void
    {
        DB::table('inventory_movements')->insert([
            'tenant_id' => app(TenantContext::class)->id(), 'item_id' => $item, 'location_id' => $location,
            'lot_id' => $lot, 'kind' => 'adjustment', 'quantity_delta' => $quantity, 'reason' => 'Synthetic stock',
            'recorded_by_user_id' => $actor->id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        ]);
    }

    private function dates(): object
    {
        return DB::selectOne("SELECT day::text AS today, (day - 1)::text AS yesterday, (day + 1)::text AS tomorrow,
            (day + 366)::text AS future FROM (SELECT timezone('UTC', clock_timestamp())::date AS day) AS utc");
    }
}
