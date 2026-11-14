<?php

namespace App\Policies;

use App\Models\Insurer;
use App\Models\User;

class InsurerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.view');
    }

    public function view(User $user, Insurer $insurer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.manage');
    }

    public function update(User $user, Insurer $insurer): bool
    {
        return $this->create($user);
    }
}
