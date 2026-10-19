<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_role_management_requires_authentication_and_the_admin_role(string $method, string $path): void
    {
        $role = Role::create(['name' => 'Custom', 'slug' => 'custom']);
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'Wildcard', 'slug' => '*']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $path = str_replace(['{role}', '{user}'], [$role->id, $user->id], $path);
        $this->json($method, $path)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->json($method, $path)->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/roles'], ['POST', '/api/roles'], ['GET', '/api/roles/{role}'],
            ['PATCH', '/api/roles/{role}'], ['PUT', '/api/roles/{role}'], ['DELETE', '/api/roles/{role}'],
            ['GET', '/api/permissions'], ['PUT', '/api/roles/{role}/permissions'], ['PUT', '/api/users/{user}/roles'],
        ];
    }

    public function test_admin_can_manage_roles_and_all_changes_have_canonical_audit_attribution(): void
    {
        $actor = $this->actingAsAdmin();
        $response = $this->postJson('/api/roles', ['name' => 'Care team', 'slug' => 'care-team', 'description' => 'Private role description'])
            ->assertCreated()->assertJsonPath('data.role.is_system', false)->assertJsonPath('data.role.permissions', []);
        $roleId = $response->json('data.role.id');
        $this->getJson('/api/roles')->assertOk()->assertJsonCount(2, 'data.roles');
        $this->getJson("/api/roles/{$roleId}")->assertOk()->assertJsonPath('data.role.id', $roleId);
        $this->patchJson("/api/roles/{$roleId}", ['name' => 'Updated care team'])->assertOk();
        $this->putJson("/api/roles/{$roleId}", ['name' => 'Updated care team'])->assertOk();
        $this->deleteJson("/api/roles/{$roleId}")->assertOk();
        $this->deleteJson("/api/roles/{$roleId}")->assertNotFound();

        $entries = AuditLog::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $entries->pluck('action')->all());
        foreach ($entries as $entry) {
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('roles', $entry->resource_type);
            $this->assertSame((string) $roleId, $entry->resource_id);
            $this->assertStringNotContainsString('Private role description', $entry->toJson());
        }
    }

    public function test_role_assignments_and_permission_changes_are_audited_only_when_changed(): void
    {
        $actor = $this->actingAsAdmin();
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Care team', 'slug' => 'care-team']);
        $permission = Permission::create(['name' => 'Read patients', 'slug' => 'patients.view']);
        $this->getJson('/api/permissions')->assertOk()->assertJsonPath('data.permissions.0.id', $permission->id);
        $this->putJson("/api/roles/{$role->id}/permissions", ['permission_ids' => [$permission->id]])
            ->assertOk()->assertJsonPath('data.role.permissions.0.id', $permission->id);
        $this->putJson("/api/roles/{$role->id}/permissions", ['permission_ids' => [$permission->id]])->assertOk();
        $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => [$role->id]])
            ->assertOk()->assertJsonPath('data.user.roles.0.id', $role->id)->assertJsonPath('data.user.permissions.0', 'patients.view');
        $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => [$role->id]])->assertOk();
        $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => []])->assertOk()->assertJsonPath('data.user.roles', []);
        $this->putJson("/api/roles/{$role->id}/permissions", ['permission_ids' => []])->assertOk();

        $entries = AuditLog::orderBy('id')->get();
        $this->assertSame(['permissions_changed', 'roles_changed', 'roles_changed', 'permissions_changed'], $entries->pluck('action')->all());
        $this->assertSame(['roles', 'users', 'users', 'roles'], $entries->pluck('resource_type')->all());
        $this->assertSame([(string) $role->id, (string) $user->id, (string) $user->id, (string) $role->id], $entries->pluck('resource_id')->all());
        $this->assertSame([$actor->id], $entries->pluck('actor_id')->unique()->values()->all());
    }

    public function test_deleting_an_assigned_role_audits_the_role_and_affected_users(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'Care team', 'slug' => 'care-team']);
        $users = User::factory()->count(2)->create();
        $role->users()->attach($users->modelKeys());
        $this->deleteJson("/api/roles/{$role->id}")->assertOk();
        $this->assertDatabaseMissing('role_user', ['role_id' => $role->id]);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertSame(['deleted', 'roles_changed', 'roles_changed'], AuditLog::orderBy('id')->pluck('action')->all());
        foreach ($users as $user) {
            $this->assertDatabaseHas('audit_logs', ['action' => 'roles_changed', 'resource_type' => 'users', 'resource_id' => (string) $user->id]);
        }
    }

    public function test_invalid_role_changes_and_system_role_mutations_do_not_emit_success_logs(): void
    {
        $admin = $this->actingAsAdmin();
        $systemRole = $admin->roles()->firstOrFail();
        $user = User::factory()->create();
        $this->postJson('/api/roles', [])->assertUnprocessable();
        $this->postJson('/api/roles', ['name' => 'Duplicate', 'slug' => 'admin'])->assertUnprocessable();
        $this->postJson('/api/roles', ['name' => 'Bad', 'slug' => 'bad', 'is_system' => true])->assertUnprocessable();
        foreach ([null, [], false] as $systemFlag) {
            $this->patchJson("/api/roles/{$systemRole->id}", ['slug' => 'renamed-admin', 'is_system' => $systemFlag])->assertUnprocessable();
        }
        $this->patchJson("/api/roles/{$systemRole->id}", ['slug' => 'renamed-admin'])->assertUnprocessable();
        $this->deleteJson("/api/roles/{$systemRole->id}")->assertUnprocessable();
        foreach ([null, 'bad', [99999], [$systemRole->id, $systemRole->id]] as $invalidIds) {
            $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => $invalidIds])->assertUnprocessable();
            $this->putJson("/api/roles/{$systemRole->id}/permissions", ['permission_ids' => $invalidIds])->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id, 'slug' => 'admin']);
    }

    public function test_removing_own_admin_role_immediately_revokes_role_management_access(): void
    {
        $actor = $this->actingAsAdmin();
        $this->putJson("/api/users/{$actor->id}/roles", ['role_ids' => []])->assertOk();
        $this->getJson('/api/roles')->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_failure_after_an_initial_role_audit_rolls_back_the_entire_cascade_and_all_audits(): void
    {
        $actor = $this->actingAsAdmin();
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Custom', 'slug' => 'custom']);
        $user->roles()->attach($role);
        $this->partialMock(AuditLogService::class)->shouldReceive('record')
            ->with('roles_changed', 'users', $user->id, $actor->id)
            ->andThrow(new \RuntimeException('Audit storage unavailable.'));

        $this->deleteJson("/api/roles/{$role->id}")->assertStatus(500);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $role->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => Role::ADMIN, 'is_system' => true]);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
