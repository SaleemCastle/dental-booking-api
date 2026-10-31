<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantProvisioning
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    public function provision(array $data, User $actor): array
    {
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $canonicalize = function (array $value) use (&$canonicalize): array {
            ksort($value);

            return array_map(fn ($item) => is_array($item) ? $canonicalize($item) : $item, $value);
        };
        // Store a keyed digest rather than credentials or a guessable password digest.
        $fingerprint = hash_hmac('sha256', json_encode($canonicalize($data), JSON_THROW_ON_ERROR), config('app.key'));

        try {
            return DB::transaction(function () use ($data, $actor, $key, $fingerprint): array {
                if ($existing = Tenant::where('provisioning_key', $key)->first()) {
                    return $this->replay($existing, $fingerprint);
                }
                if (Tenant::where('slug', $data['slug'])->exists()) {
                    throw ValidationException::withMessages(['slug' => ['This clinic slug is already in use.']]);
                }
                // Existing identities must never be claimed or have credentials reset by onboarding.
                if (User::where('email', $data['admin']['email'])->exists()) {
                    throw ValidationException::withMessages(['admin.email' => ['This email is already in use.']]);
                }
                $admin = User::create($data['admin']);
                unset($data['admin']);
                $tenant = new Tenant($data + ['active' => true]);
                $tenant->forceFill(['provisioning_key' => $key, 'provisioning_fingerprint' => $fingerprint, 'initial_admin_id' => $admin->id])->save();
                $tenant->users()->attach($admin);
                $this->context->run($tenant, function () use ($tenant, $admin, $actor): void {
                    app(RoleSeeder::class)->run();
                    app(PermissionSeeder::class)->run();
                    $admin->roles()->attach(Role::where('slug', Role::CLINIC_ADMIN)->sole());
                    $this->audit->record('provisioned', 'tenants', $tenant->id, $actor->id);
                });

                return [$tenant, true];
            }, 3);
        } catch (QueryException $exception) {
            // The unique key also serializes simultaneous requests across operators.
            if ($existing = Tenant::where('provisioning_key', $key)->first()) {
                return $this->replay($existing, $fingerprint);
            }
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Clinic or admin identity already exists.');
            }
            throw $exception;
        }
    }

    private function replay(Tenant $tenant, string $fingerprint): array
    {
        abort_unless(hash_equals($tenant->provisioning_fingerprint, $fingerprint), 409, 'Idempotency key has already been used with different input.');

        return [$tenant, false];
    }

    public function changeStatus(Tenant $tenant, string $status, User $actor): Tenant
    {
        return DB::transaction(function () use ($tenant, $status, $actor): Tenant {
            $current = Tenant::lockForUpdate()->findOrFail($tenant->id);
            $active = $status === 'active';
            if ($current->active !== $active) {
                $current->update(['active' => $active]);
                $this->context->run($current, fn () => $this->audit->record($active ? 'activated' : 'suspended', 'tenants', $current->id, $actor->id));
            }

            return $current;
        }, 3);
    }
}
