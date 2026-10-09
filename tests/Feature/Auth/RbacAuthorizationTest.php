<?php

namespace Tests\Feature\Auth;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasColumns('roles', [
            'id',
            'name',
            'slug',
            'description',
            'is_system',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasColumns('permissions', [
            'id',
            'name',
            'slug',
            'resource',
            'action',
            'description',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('role_user'));
        $this->assertTrue(Schema::hasColumns('role_user', ['id', 'role_id', 'user_id']));

        $this->assertTrue(Schema::hasTable('permission_role'));
        $this->assertTrue(Schema::hasColumns('permission_role', ['id', 'permission_id', 'role_id']));
    }

    public function test_user_roles_flatten_role_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Clinic Admin',
            'slug' => 'clinic-admin',
            'description' => 'Can manage clinic operations.',
            'is_system' => true,
        ]);
        $permission = Permission::create([
            'name' => 'View patients',
            'slug' => 'patients.view',
            'resource' => 'patients',
            'action' => 'view',
        ]);

        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole('clinic-admin'));
        $this->assertTrue($user->hasRole('Clinic Admin'));
        $this->assertTrue($user->hasPermission('patients.view'));
        $this->assertSame(['patients.view'], $user->permissionSlugs());
    }

    public function test_authorization_helpers_support_resource_policy_patterns(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Patient Manager',
            'slug' => 'patient-manager',
        ]);
        $permission = Permission::create([
            'name' => 'Manage patients',
            'slug' => 'patients.*',
            'resource' => 'patients',
            'action' => '*',
        ]);

        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $this->assertTrue($user->canAccessResource('patients', 'view'));
        $this->assertTrue($user->canAccessResource('patients', 'update'));
        $this->assertFalse($user->canAccessResource('appointments', 'view'));
        $this->assertTrue(Gate::forUser($user)->allows('update', new Patient));
    }

    public function test_authenticated_user_response_includes_roles_and_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Scheduler',
            'slug' => 'scheduler',
        ]);
        $permission = Permission::create([
            'name' => 'View appointments',
            'slug' => 'appointments.view',
            'resource' => 'appointments',
            'action' => 'view',
        ]);

        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0.slug', 'scheduler')
            ->assertJsonPath('data.roles.0.permissions.0.slug', 'appointments.view')
            ->assertJsonPath('data.permissions.0', 'appointments.view');
    }
}
