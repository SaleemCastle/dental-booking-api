<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\TreatmentPlan;
use App\Models\User;

class TreatmentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::DENTIST]) || $user->canAccessResource('treatment_plans', 'view');
    }

    public function view(User $user, TreatmentPlan $plan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::DENTIST) && ! $user->hasRole(Role::RECEPTIONIST));
    }

    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $this->create($user);
    }
}
