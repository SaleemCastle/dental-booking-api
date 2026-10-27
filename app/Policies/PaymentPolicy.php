<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\Role;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING, Role::AUDITOR]) || $user->canAccessResource('payments', 'view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('payments', 'create');
    }
}
