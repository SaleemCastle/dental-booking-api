<?php

namespace App\Insurance;

use App\Models\User;

final class InsuranceClaimAccessPolicy
{
    public static function allows(User $actor, string $permission): bool
    {
        return $actor->isMemberOfCurrentTenant()
            && $actor->hasPermission('patients.view')
            && $actor->hasPermission('insurance_claims.'.$permission);
    }
}
