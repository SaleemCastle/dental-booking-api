<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'idempotency_key' => 'onboarding-001', 'name' => 'Independent Clinic', 'slug' => 'independent-clinic',
            'contact_email' => 'clinic@example.test', 'contact_phone' => '+571234567890',
            'address' => 'Clinic address', 'timezone' => 'America/Bogota', 'currency' => 'COP',
            'branding' => ['display_name' => 'Independent', 'primary_color' => '#123abc', 'logo_url' => 'https://example.test/logo.png'],
            'admin' => ['name' => 'Clinic Owner', 'email' => 'owner@example.test', 'password' => 'Strong-password-123'],
        ];
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_operator' => true])->save();
        $user->tenants()->detach();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_only_platform_operators_can_manage_tenants_even_without_clinic_membership(): void
    {
        $this->postJson('/api/platform/tenants', $this->payload())->assertUnauthorized();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', Role::ADMIN)->sole());
        Sanctum::actingAs($admin);
        $this->postJson('/api/platform/tenants', $this->payload())->assertForbidden();
        $this->getJson('/api/platform/tenants')->assertForbidden();
        $this->getJson('/api/platform/tenants/1')->assertForbidden();
        $this->patchJson('/api/platform/tenants/1/status', ['status' => 'suspended'])->assertForbidden();
        $operator = $this->operator();
        $this->getJson('/api/platform/tenants')->assertOk();
        $this->getJson('/api/patients')->assertForbidden();
        User::whereKey($operator->id)->update(['is_platform_operator' => false]);
        $this->getJson('/api/platform/tenants')->assertForbidden();
    }

    public function test_provisioning_creates_an_isolated_clinic_with_safe_defaults_and_audit(): void
    {
        $operator = $this->operator();
        $response = $this->postJson('/api/platform/tenants', $this->payload())->assertCreated()
            ->assertJsonPath('data.tenant.status', 'active')
            ->assertJsonPath('data.tenant.timezone', 'America/Bogota')
            ->assertJsonPath('data.tenant.currency', 'COP')
            ->assertJsonPath('data.tenant.branding.primary_color', '#123abc')
            ->assertJsonMissingPath('data.tenant.provisioning_fingerprint');
        $tenant = Tenant::findOrFail($response->json('data.tenant.id'));
        $admin = User::findOrFail($response->json('data.initial_admin_id'));
        $this->assertTrue(Hash::check($this->payload()['admin']['password'], $admin->password));
        $this->assertFalse($admin->is_platform_operator);
        $this->assertSame([$tenant->id], $admin->tenants()->pluck('tenants.id')->all());
        $this->assertFalse($operator->tenants()->whereKey($tenant->id)->exists());
        app(TenantContext::class)->run($tenant, function () use ($admin, $operator, $tenant): void {
            $this->assertSame(6, Role::count());
            $this->assertTrue($admin->hasRole(Role::ADMIN));
            $this->assertTrue($admin->hasPermission('patients.view'));
            $this->assertSame(0, DB::table('permission_role')->whereIn('role_id', Role::where('slug', '!=', Role::ADMIN)->pluck('id'))->count());
            $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'actor_id' => $operator->id, 'action' => 'provisioned']);
            $this->assertSame(0, Patient::count());
            $this->assertSame(0, DB::table('payment_provider_configurations')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(0, DB::table('communication_configurations')->where('tenant_id', $tenant->id)->count());
        });
        Sanctum::actingAs($admin);
        $this->withHeader('X-Tenant-Id', (string) $tenant->id)->getJson('/api/patients')->assertOk();
        $this->getJson('/api/platform/tenants')->assertForbidden();
    }

    public function test_retries_do_not_duplicate_data_or_reset_status_and_conflicting_input_is_rejected(): void
    {
        $this->operator();
        $response = $this->postJson('/api/platform/tenants', $this->payload())->assertCreated();
        $id = $response->json('data.tenant.id');
        $this->patchJson('/api/platform/tenants/'.$id.'/status', ['status' => 'suspended'])->assertOk();
        $this->postJson('/api/platform/tenants', array_reverse($this->payload(), true))->assertOk()
            ->assertJsonPath('data.tenant.id', $id)->assertJsonPath('data.tenant.status', 'suspended');
        $this->postJson('/api/platform/tenants', array_replace($this->payload(), ['name' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('tenant_user', 1);
        $this->assertDatabaseCount('role_user', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->postJson('/api/platform/tenants', array_replace($this->payload(), ['idempotency_key' => 'different']))->assertUnprocessable();
    }

    public function test_suspension_blocks_existing_tokens_and_reactivation_restores_access(): void
    {
        $operator = $this->operator();
        $response = $this->postJson('/api/platform/tenants', $this->payload())->assertCreated();
        $id = $response->json('data.tenant.id');
        $admin = User::findOrFail($response->json('data.initial_admin_id'));
        // Use a real issued token across status changes.
        $token = $admin->createToken('existing')->plainTextToken;
        $this->patchJson('/api/platform/tenants/'.$id.'/status', ['status' => 'suspended'])->assertOk();
        $this->patchJson('/api/platform/tenants/'.$id.'/status', ['status' => 'suspended'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('X-Tenant-Id', (string) $id)->getJson('/api/patients')->assertForbidden();
        $this->getJson('/api/user')->assertForbidden();
        $this->postJson('/api/patients', [])->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->getJson('/api/tenants')->assertOk()->assertJsonCount(0, 'data.tenants');
        Sanctum::actingAs($operator);
        $this->patchJson('/api/platform/tenants/'.$id.'/status', ['status' => 'active'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/patients')->assertOk();
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $id, 'action' => 'activated', 'actor_id' => $operator->id]);
    }

    public function test_audit_failure_rolls_back_all_provisioning_and_status_changes(): void
    {
        $this->operator();
        $this->mock(AuditLogService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/platform/tenants', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenant_user', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->patchJson('/api/platform/tenants/1/status', ['status' => 'suspended'])->assertStatus(500);
        $this->assertTrue(Tenant::findOrFail(1)->active);
    }

    public function test_invalid_profile_and_existing_identity_are_rejected_without_side_effects(): void
    {
        $this->operator();
        $data = array_replace($this->payload(), ['timezone' => 'Invalid/Zone', 'currency' => 'bad', 'branding' => ['primary_color' => 'red']]);
        $this->postJson('/api/platform/tenants', $data)->assertUnprocessable()->assertJsonValidationErrors(['timezone', 'currency', 'branding.primary_color']);
        $existing = User::factory()->create(['email' => 'owner@example.test']);
        $password = $existing->password;
        $this->postJson('/api/platform/tenants', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('admin.email');
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertDatabaseCount('tenants', 1);
        $this->patchJson('/api/platform/tenants/1/status', ['status' => 'deleted'])->assertUnprocessable();
    }

    public function test_operator_grants_require_trusted_command_and_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_platform_operator' => true])->save();
        $this->assertFalse($user->fresh()->is_platform_operator);
        $this->artisan('platform:operator', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_operator);
        $this->artisan('platform:operator', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_operator);
    }

    public function test_clinic_admin_cannot_take_over_a_platform_operator_identity(): void
    {
        $operator = $this->operator();
        $operator->tenants()->attach(1);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', Role::ADMIN)->sole());
        Sanctum::actingAs($admin);
        $this->patchJson('/api/users/'.$operator->id, ['password' => 'Takeover-password-123'])->assertForbidden();
        $this->deleteJson('/api/users/'.$operator->id)->assertForbidden();
        $this->patchJson('/api/users/'.$admin->id, ['is_platform_operator' => true])->assertOk();
        $this->assertFalse($admin->fresh()->is_platform_operator);
    }
}
