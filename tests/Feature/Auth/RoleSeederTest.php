<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_creates_stable_system_roles_idempotently(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(6, Role::count());
        $this->assertSame(Role::SYSTEM_ROLE_SLUGS, Role::orderBy('id')->pluck('slug')->all());

        foreach (Role::SYSTEM_ROLE_SLUGS as $slug) {
            $this->assertDatabaseHas('roles', [
                'slug' => $slug,
                'is_system' => true,
            ]);
        }
    }

    public function test_role_seeder_updates_existing_role_records_by_slug(): void
    {
        Role::create([
            'name' => 'Old Admin',
            'slug' => Role::ADMIN,
            'description' => 'Outdated description.',
            'is_system' => false,
        ]);

        $this->seed(RoleSeeder::class);

        $admin = Role::where('slug', Role::ADMIN)->firstOrFail();

        $this->assertSame(6, Role::count());
        $this->assertSame('Admin', $admin->name);
        $this->assertTrue($admin->is_system);
        $this->assertSame('Full administrative access across the clinic workspace.', $admin->description);
    }

    public function test_database_seeder_can_be_run_more_than_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, Role::count());
        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }
}
