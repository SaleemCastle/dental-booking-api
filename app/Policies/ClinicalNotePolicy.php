<?php

namespace App\Policies;

use App\Models\ClinicalNote;
use App\Models\Role;
use App\Models\User;

class ClinicalNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::DENTIST])
            || $user->canAccessResource('clinical_notes', 'view');
    }

    public function view(User $user, ClinicalNote $clinicalNote): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, ClinicalNote $clinicalNote): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, ClinicalNote $clinicalNote): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::DENTIST) && ! $user->hasRole(Role::RECEPTIONIST));
    }
}
