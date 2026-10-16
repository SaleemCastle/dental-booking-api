<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->can($user, 'update');
    }

    public function updateMedical(User $user, Patient $patient): bool
    {
        return $this->can($user, 'medical.update');
    }

    public function archive(User $user, Patient $patient): bool
    {
        return $this->can($user, 'archive');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return false;
    }

    private function can(User $user, string $action): bool
    {
        return $user->hasRole(Role::ADMIN) || $user->canAccessResource('patients', $action);
    }
}
