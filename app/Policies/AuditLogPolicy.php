<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roles()->whereIn('slug', [Role::ADMIN, Role::AUDITOR])->exists();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user);
    }
}
