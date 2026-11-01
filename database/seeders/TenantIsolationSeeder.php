<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

class TenantIsolationSeeder extends Seeder
{
    private const TENANT_NAME = 'Isolation Test Clinic';

    private const TENANT_SLUG = 'isolation-test-clinic';

    public function run(): void
    {
        $this->ensureSeederCanRun();

        $email = $this->requiredEnvironmentVariable(
            'ISOLATION_ADMIN_EMAIL'
        );

        $password = $this->requiredEnvironmentVariable(
            'ISOLATION_ADMIN_PASSWORD'
        );

        $tenant = Tenant::query()->updateOrCreate(
            [
                'slug' => self::TENANT_SLUG,
            ],
            [
                'name' => self::TENANT_NAME,
                'active' => true,
            ]
        );

        app(TenantContext::class)->run(
            $tenant,
            function () use ($tenant, $email, $password): void {
                $this->seedRolesAndPermissions();

                $user = $this->createIsolationAdministrator(
                    $email,
                    $password
                );

                $user->tenants()->syncWithoutDetaching([
                    $tenant->id,
                ]);

                $adminRole = Role::query()
                    ->where('slug', Role::ADMIN)
                    ->firstOrFail();

                $user->roles()->syncWithoutDetaching([
                    $adminRole->id,
                ]);

                $patient = $this->createIsolationPatient();

                $this->command?->info(
                    sprintf(
                        'Isolation tenant "%s" is ready.',
                        $tenant->name
                    )
                );

                $this->command?->info(
                    sprintf(
                        'Isolation administrator: %s',
                        $email
                    )
                );

                $this->command?->info(
                    sprintf(
                        'Isolation patient ID: %s',
                        $patient->id
                    )
                );
            }
        );
    }

    private function seedRolesAndPermissions(): void
    {
        /*
         * RoleSeeder and PermissionSeeder execute inside the isolation
         * tenant context, so tenant-scoped roles and their permissions
         * are created for this clinic.
         */
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);
    }

    private function createIsolationAdministrator(
        string $email,
        string $password
    ): User {
        return User::query()->updateOrCreate(
            [
                'email' => $email,
            ],
            [
                'name' => 'Isolation Test Administrator',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
    }

    private function createIsolationPatient(): Patient
    {
        $existingPatient = Patient::query()
            ->where('firstName', 'ISOLATION')
            ->where('lastName', 'TENANT-ONLY')
            ->first();

        if ($existingPatient) {
            return $existingPatient;
        }

        return Patient::factory()->create([
            'firstName' => 'ISOLATION',
            'lastName' => 'TENANT-ONLY',
            'notes' => 'Cross-tenant isolation test fixture.',
        ]);
    }

    private function ensureSeederCanRun(): void
    {
        if (! app()->environment(['local', 'development', 'staging'])) {
            throw new RuntimeException(
                sprintf(
                    'TenantIsolationSeeder cannot run in the "%s" environment.',
                    app()->environment()
                )
            );
        }
    }

    private function requiredEnvironmentVariable(string $name): string
    {
        $value = env($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                sprintf(
                    'Required environment variable "%s" is not configured.',
                    $name
                )
            );
        }

        return trim($value);
    }
}
