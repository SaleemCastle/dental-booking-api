<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class StagingUserSeeder extends Seeder
{
    /**
     * Create the administrator used for staging authentication
     * and application testing.
     */
    public function run(): void
    {
        $this->ensureSeederCanRun();

        $name = $this->requiredEnvironmentVariable(
            'STAGING_ADMIN_NAME'
        );

        $email = $this->requiredEnvironmentVariable(
            'STAGING_ADMIN_EMAIL'
        );

        $password = $this->requiredEnvironmentVariable(
            'STAGING_ADMIN_PASSWORD'
        );

        $tenant = Tenant::query()
            ->where('slug', Tenant::DEFAULT_SLUG)
            ->firstOrFail();

        $adminRole = Role::query()
            ->where('slug', Role::ADMIN)
            ->firstOrFail();

        $user = User::query()->updateOrCreate(
            [
                'email' => $email,
            ],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        $user->tenants()->syncWithoutDetaching([
            $tenant->id,
        ]);

        /*
         * Resolve the tenant context before assigning the role because
         * role membership is tenant-scoped by the application.
         */
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->id() !== $tenant->id) {
            throw new RuntimeException(
                'The active tenant context does not match the staging tenant.'
            );
        }

        $user->roles()->syncWithoutDetaching([
            $adminRole->id,
        ]);

        $this->command?->info(
            sprintf(
                'Staging administrator "%s" is ready for tenant "%s".',
                $email,
                $tenant->name
            )
        );
    }

    /**
     * Restrict this seeder to non-production environments.
     */
    private function ensureSeederCanRun(): void
    {
        if (! app()->environment(['local', 'development', 'staging'])) {
            throw new RuntimeException(
                sprintf(
                    'StagingUserSeeder cannot run in the "%s" environment.',
                    app()->environment()
                )
            );
        }
    }

    /**
     * Retrieve a required environment variable.
     */
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