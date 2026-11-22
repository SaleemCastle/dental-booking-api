<?php

namespace App\Insurance;

use App\Models\User;

final class InsurerObligationAccessPolicy
{
    private const PERMISSIONS = [
        'view' => 'insurance_obligations.view',
        'propose' => 'insurance_obligations.propose',
        'approve' => 'insurance_obligations.approve',
    ];

    public static function allows(User $user, string $action): bool
    {
        return isset(self::PERMISSIONS[$action])
            && $user->isMemberOfCurrentTenant()
            && $user->hasPermission(self::PERMISSIONS[$action])
            && $user->hasPermission('invoices.view')
            && $user->hasPermission('patients.view');
    }
}
