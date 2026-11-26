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

class EquipmentPostgresTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/equipment';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('pgsql', DB::getDriverName(), 'Use a disposable PostgreSQL database.');
    }

    public function test_clinic_tag_status_and_stale_edit_guards(): void
    {
        $this->actor();
        $id = $this->equipment();
        $url = self::ROOT.'/'.$id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.version', 1);
        $this->postJson(self::ROOT, ['asset_tag' => 'CHAIR-01', 'name' => 'Duplicate'])->assertConflict();
        $this->patchJson($url, ['version' => 1, 'status' => 'out_of_service', 'location_label' => 'Service bay'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.location_label', 'Service bay');
        $this->patchJson($url, ['version' => 1, 'status' => 'active'])->assertConflict();
        $this->getJson(self::ROOT.'?status=out_of_service')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->patchJson($url, ['version' => 2, 'status' => 'retired'])->assertOk()->assertJsonPath('data.version', 3);
        $this->patchJson($url, ['version' => 3, 'status' => 'active'])->assertConflict();
        $this->patchJson($url, ['version' => 3, 'asset_tag' => 'OTHER'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'equipment.status_retired', 'resource_id' => (string) $id]);
        $this->rejectSql(fn () => DB::table('equipment_assets')->where('id', $id)->update(['status' => 'active', 'version' => 4]));
        $this->rejectSql(fn () => DB::table('equipment_assets')->where('id', $id)->delete());
    }

    public function test_maintenance_and_warranty_history_validate_dates_and_audit_replays(): void
    {
        $actor = $this->actor();
        $url = self::ROOT.'/'.$this->equipment();
        $service = ['performed_on' => '2026-01-10', 'next_due_on' => '2027-01-10', 'details' => 'Replaced test seal'];
        $warranty = ['starts_on' => '2026-01-10', 'ends_on' => '2027-01-10', 'provider' => 'Example Provider'];
        $this->postJson($url.'/maintenance', [...$service, 'next_due_on' => '2025-12-31'], $this->key())->assertUnprocessable();
        $this->postJson($url.'/warranties', [...$warranty, 'ends_on' => '2025-12-31'], $this->key())->assertUnprocessable();
        $this->postJson($url.'/maintenance', $service)->assertUnprocessable();
        $key = $this->key();
        $serviceId = $this->postJson($url.'/maintenance', $service, $key)->assertCreated()
            ->assertJsonPath('data.record.kind', 'maintenance')->assertJsonPath('data.record.recorded_by_user_id', $actor->id)
            ->json('data.record.id');
        $this->postJson($url.'/maintenance', [...$service, 'provider' => null], $key)->assertOk()
            ->assertJsonPath('data.record.id', $serviceId)->assertJsonPath('data.replayed', true);
        $this->postJson($url.'/maintenance', [...$service, 'details' => 'Changed'], $key)->assertConflict();
        $this->postJson($url.'/warranties', $warranty, $key)->assertConflict();
        $this->postJson($url.'/warranties', $warranty, $this->key())->assertCreated()->assertJsonPath('data.record.kind', 'warranty');
        $this->getJson($url.'/maintenance')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.request_hash');
        $this->getJson($url.'/warranties')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ends_on', '2027-01-10');
        $this->rejectSql(fn () => DB::table('equipment_records')->where('id', $serviceId)->update(['details' => 'Changed']));
        $this->rejectSql(fn () => DB::table('equipment_records')->where('id', $serviceId)->delete());
        $this->rejectSql(fn () => DB::statement('TRUNCATE equipment_records'));
        $this->assertSame(2, DB::table('audit_logs')->where('resource_type', 'equipment_records')->count());
        foreach (['inventory_movements', 'invoices', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_foreign_clinic_ids_and_wildcard_permissions_are_denied(): void
    {
        $this->getJson(self::ROOT)->assertUnauthorized();
        $this->actor(['equipment.*', '*']);
        $this->getJson(self::ROOT)->assertForbidden();
        $this->postJson(self::ROOT, [])->assertForbidden();
        $this->actor();
        $id = $this->equipment();
        $other = Tenant::factory()->create();
        app(TenantContext::class)->run($other, function () use ($other, $id) {
            $actor = $this->actor();
            $headers = ['X-Tenant-Id' => (string) $other->id];
            $this->getJson(self::ROOT.'/'.$id, $headers)->assertNotFound();
            $this->getJson(self::ROOT.'/'.$id.'/maintenance', $headers)->assertNotFound();
            $this->postJson(self::ROOT.'/'.$id.'/maintenance', ['performed_on' => '2026-01-10', 'details' => 'Foreign'], $headers + $this->key())->assertNotFound();
            $this->equipment($headers);
            $this->getJson(self::ROOT, $headers)->assertOk()->assertJsonCount(1, 'data');
            $this->rejectSql(fn () => DB::table('equipment_records')->insert([
                'tenant_id' => $other->id, 'equipment_id' => $id, 'kind' => 'maintenance', 'performed_on' => '2026-01-10',
                'details' => 'Foreign', 'recorded_by_user_id' => $actor->id, 'idempotency_key' => (string) Str::uuid(),
                'request_hash' => str_repeat('a', 64),
            ]), '23503');
        });
    }

    public function test_failed_audit_rolls_back_maintenance_record(): void
    {
        $this->actor();
        $id = $this->equipment();
        $url = self::ROOT.'/'.$id.'/maintenance';
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->postJson($url, ['performed_on' => '2026-01-10', 'details' => 'Repair'], $this->key())->assertInternalServerError();
        $this->assertDatabaseCount('equipment_records', 0);
    }

    private function rejectSql(callable $operation, string $state = '23514'): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected PostgreSQL to reject this operation.');
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }

    private function actor(array $grants = ['equipment.view', 'equipment.manage', 'equipment.record_history']): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => 'equipment-test-'.$user->id, 'name' => 'Equipment test']);
        foreach ($grants as $grant) {
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $grant], ['name' => $grant]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function equipment(array $headers = []): int
    {
        return $this->postJson(self::ROOT, ['asset_tag' => 'CHAIR-01', 'name' => 'Test chair', 'serial_number' => 'ABC-123'], $headers)
            ->assertCreated()->json('data.id');
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
