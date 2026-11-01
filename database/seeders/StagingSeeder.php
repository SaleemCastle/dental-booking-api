<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class StagingSeeder extends Seeder
{
    /**
     * Seed the minimum data required for the staging environment.
     */
    public function run(): void
    {
        $this->ensureSeederCanRun();

        $this->call([
            TenantSeeder::class,
            RoleSeeder::class,
            PermissionSeeder::class,
            AppointmentLocationSeeder::class,
            StagingUserSeeder::class,
        ]);
    }

    /**
     * Prevent staging seed data from accidentally being created
     * in an unsupported environment.
     */
    private function ensureSeederCanRun(): void
    {
        if (! app()->environment(['local', 'development', 'staging'])) {
            throw new RuntimeException(
                sprintf(
                    'StagingSeeder cannot run in the "%s" environment.',
                    app()->environment()
                )
            );
        }
    }
}