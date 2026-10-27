<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_idempotent_and_uses_concrete_policy_slugs(): void
    {
        $this->seed(PermissionSeeder::class);
        $originalIds = Permission::orderBy('slug')->pluck('id', 'slug')->all();
        $this->seed(PermissionSeeder::class);

        $this->assertSame($originalIds, Permission::orderBy('slug')->pluck('id', 'slug')->all());
        $this->assertDatabaseCount('permissions', 58);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('users', 0);

        foreach (['appointments', 'treatments', 'tasks', 'clinical_notes', 'dentists', 'messages', 'referrals', 'users', 'roles'] as $resource) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $this->assertDatabaseHas('permissions', ['slug' => $resource.'.'.$action, 'resource' => $resource, 'action' => $action]);
            }
        }
        foreach (['invoices' => ['view', 'create', 'update'], 'payments' => ['view', 'create']] as $resource => $actions) {
            foreach ($actions as $action) {
                $this->assertDatabaseHas('permissions', ['slug' => $resource.'.'.$action]);
            }
        }
        foreach (['view', 'create', 'update', 'archive', 'medical.update'] as $action) {
            $this->assertDatabaseHas('permissions', ['slug' => 'patients.'.$action, 'resource' => 'patients', 'action' => $action]);
        }
        foreach (['dashboard', 'permissions', 'audit_logs'] as $resource) {
            $this->assertDatabaseHas('permissions', ['slug' => $resource.'.view', 'resource' => $resource, 'action' => 'view']);
        }
        foreach (['view', 'create'] as $action) {
            $this->assertDatabaseHas('permissions', ['slug' => 'dental_charts.'.$action]);
        }
        foreach (['view', 'create', 'update'] as $action) {
            $this->assertDatabaseHas('permissions', ['slug' => 'treatment_plans.'.$action]);
        }
        foreach (['view', 'create', 'archive', 'delete'] as $action) {
            $this->assertDatabaseHas('permissions', ['slug' => 'patient_documents.'.$action]);
        }
        $this->assertDatabaseMissing('permissions', ['slug' => 'patients.delete']);
        $this->assertFalse(Permission::where('slug', 'like', '%*%')->exists());
    }

    public function test_seeding_repairs_metadata_without_replacing_permission_ids(): void
    {
        $existing = Permission::create(['slug' => 'appointments.create', 'name' => 'Old label']);

        $this->seed(PermissionSeeder::class);

        $existing->refresh();
        $this->assertSame('Create Appointments', $existing->name);
        $this->assertSame('appointments', $existing->resource);
        $this->assertSame('create', $existing->action);
        $this->assertSame($existing->id, Permission::where('slug', 'appointments.create')->sole()->id);
    }

    public function test_admin_receives_catalog_permissions_without_detaching_custom_permissions_or_changing_other_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = Role::where('slug', Role::ADMIN)->sole();
        $receptionist = Role::where('slug', Role::RECEPTIONIST)->sole();
        $custom = Role::create(['slug' => 'custom-staff', 'name' => 'Custom Staff']);
        $permission = Permission::create(['slug' => 'custom.review', 'name' => 'Custom review']);
        foreach ([$admin, $receptionist, $custom] as $role) {
            $role->permissions()->attach($permission);
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame(59, $admin->permissions()->count());
        $this->assertDatabaseCount('permission_role', 61);
        $this->assertSame([$permission->id], $receptionist->permissions()->pluck('permissions.id')->all());
        $this->assertSame([$permission->id], $custom->permissions()->pluck('permissions.id')->all());
        foreach ([Role::DENTIST, Role::HYGIENIST, Role::BILLING, Role::AUDITOR] as $slug) {
            $this->assertSame(0, Role::where('slug', $slug)->sole()->permissions()->count());
        }
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_current_user_and_permission_selector_expose_seeded_slugs(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', Role::ADMIN)->sole());
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user')->assertOk();
        $this->assertCount(58, $response->json('data.permissions'));
        foreach (['appointments.create', 'appointments.update', 'patients.medical.update', 'clinical_notes.create', 'tasks.update', 'audit_logs.view'] as $slug) {
            $this->assertContains($slug, $response->json('data.permissions'));
        }
        $catalog = $this->getJson('/api/permissions')->assertOk()->assertJsonCount(58, 'data.permissions');
        $this->assertContains('appointments.create', array_column($catalog->json('data.permissions'), 'slug'));
    }
}
