<?php

namespace App\Insurance;

use App\Models\User;

final class InsuranceReportAccessPolicy
{
    public static function allows(User $actor): bool
    {
        return $actor->isMemberOfCurrentTenant()
            && $actor->hasPermission('patients.view')
            && $actor->hasPermission('insurance_reports.view');
    }
}
