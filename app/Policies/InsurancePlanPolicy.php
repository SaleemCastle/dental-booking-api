<?php

namespace App\Policies;

use App\Models\InsurancePlan;
use App\Models\User;

class InsurancePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.view');
    }

    public function view(User $user, InsurancePlan $plan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.manage');
    }

    public function update(User $user, InsurancePlan $plan): bool
    {
        return $this->create($user);
    }
}
