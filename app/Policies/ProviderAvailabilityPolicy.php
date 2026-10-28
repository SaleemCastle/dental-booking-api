<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class ProviderAvailabilityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::ADMIN) || $user->canAccessResource('provider_availabilities', 'view');
    }

    public function update(User $user): bool
    {
        return $user->hasRole(Role::ADMIN) || $user->canAccessResource('provider_availabilities', 'update');
    }
}
