<?php

namespace App\Policies;

use App\Models\PatientDocument;
use App\Models\Role;
use App\Models\User;

class PatientDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::DENTIST])
            || $user->canAccessResource('patient_documents', 'view');
    }

    public function view(User $user, PatientDocument $document): bool
    {
        return $this->viewAny($user);
    }

    public function download(User $user, PatientDocument $document): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function archive(User $user, PatientDocument $document): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, PatientDocument $document): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->hasRole(Role::ADMIN)
            || ($user->hasRole(Role::DENTIST) && ! $user->hasRole(Role::RECEPTIONIST));
    }
}
