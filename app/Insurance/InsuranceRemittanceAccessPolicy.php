<?php

namespace App\Insurance;

use App\Models\User;

final class InsuranceRemittanceAccessPolicy
{
    private const ACTIONS = ['view', 'record', 'correct'];

    public static function allows(User $actor, string $action): bool
    {
        return in_array($action, self::ACTIONS, true)
            && $actor->isMemberOfCurrentTenant()
            && $actor->hasPermission('patients.view')
            && $actor->hasPermission('insurance_remittances.'.$action);
    }
}
