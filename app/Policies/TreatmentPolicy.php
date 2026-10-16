<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Treatment;
use App\Models\User;

class TreatmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, Treatment $treatment): bool
    {
        return $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Treatment $treatment): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Treatment $treatment): bool
    {
        return $this->can($user, 'delete');
    }

    private function can(User $user, string $action): bool
    {
        return $user->hasRole(Role::ADMIN) || $user->canAccessResource('treatments', $action);
    }
}
