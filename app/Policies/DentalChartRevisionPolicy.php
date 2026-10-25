<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class DentalChartRevisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::DENTIST]) || $user->canAccessResource('dental_charts', 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::DENTIST) && ! $user->hasRole(Role::RECEPTIONIST));
    }
}
