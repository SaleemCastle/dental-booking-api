<?php

namespace App\Insurance;

use App\Models\User;

final class InsurerReceiptAccessPolicy
{
    private const PERMISSIONS = [
        'view' => 'insurance_receipts.view',
        'record' => 'insurance_receipts.record',
        'verify' => 'insurance_receipts.verify',
    ];

    public static function allows(User $user, string $action): bool
    {
        return isset(self::PERMISSIONS[$action])
            && $user->isMemberOfCurrentTenant()
            && $user->hasPermission(self::PERMISSIONS[$action]);
    }
}
