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
        if ($requestedId !== null && (! preg_match('/\A[1-9][0-9]*\z/', $requestedId) || strlen($requestedId) > 18)) {
            throw ValidationException::withMessages(['tenant_id' => ['X-Tenant-Id must be a positive clinic ID.']]);
        }

        // Query current membership rather than trusting cached relations or token claims.
        $tenant = $user->tenants()->where('active', true)
            ->when($requestedId === null,
                fn ($query) => $query->where('slug', Tenant::DEFAULT_SLUG),
                fn ($query) => $query->whereKey($requestedId))
            ->first();

        if (! $tenant) {
            throw new AuthorizationException('Current clinic membership is required.');
        }

        return $tenant;
    }
}
