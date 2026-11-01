<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class TenantResolver
{
    public function resolve(User $user, ?string $requestedId = null): Tenant
    {
        if ($requestedId !== null) {
            return $this->resolveRequestedTenant($user, $requestedId);
        }

        return $this->resolveSingleTenantMembership($user);
    }

    private function resolveRequestedTenant(
        User $user,
        string $requestedId
    ): Tenant {
        $this->validateRequestedTenantId($requestedId);

        $tenant = $user->tenants()
            ->where('active', true)
            ->whereKey($requestedId)
            ->first();

        if (! $tenant) {
            throw new AuthorizationException(
                'Current clinic membership is required.'
            );
        }

        return $tenant;
    }

    private function resolveSingleTenantMembership(User $user): Tenant
    {
        $tenants = $user->tenants()
            ->where('active', true)
            ->limit(2)
            ->get();

        if ($tenants->isEmpty()) {
            throw new AuthorizationException(
                'Current clinic membership is required.'
            );
        }

        if ($tenants->count() > 1) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'A clinic must be selected for this account.',
                ],
            ]);
        }

        return $tenants->first();
    }

    private function validateRequestedTenantId(string $requestedId): void
    {
        if (
            ! preg_match('/\A[1-9][0-9]*\z/', $requestedId)
            || strlen($requestedId) > 18
        ) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'X-Tenant-Id must be a positive clinic ID.',
                ],
            ]);
        }
    }
}