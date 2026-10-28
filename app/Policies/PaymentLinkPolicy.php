<?php

namespace App\Policies;

use App\Models\PaymentLink;
use App\Models\Role;
use App\Models\User;

class PaymentLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING, Role::AUDITOR]) || $user->canAccessResource('payment_links', 'view');
    }

    public function view(User $user, PaymentLink $link): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('payment_links', 'create');
    }

    public function cancel(User $user, PaymentLink $link): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('payment_links', 'cancel');
    }

    public function refresh(User $user, PaymentLink $link): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('payment_links', 'refresh');
    }
}
