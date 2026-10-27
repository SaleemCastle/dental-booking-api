<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING, Role::AUDITOR]) || $user->canAccessResource('invoices', 'view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('invoices', 'create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::BILLING]) || $user->canAccessResource('invoices', 'update');
    }
}
