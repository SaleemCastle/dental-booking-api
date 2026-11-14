<?php

namespace App\Policies;

use App\Models\InsurancePlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InsurancePlanVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.view');
    }

    public function view(User $user, InsurancePlanVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('insurance_catalog.manage');
    }

    public function update(User $user, InsurancePlanVersion $version): bool
    {
        return $this->create($user);
    }

    public function review(User $user, InsurancePlanVersion $version): bool
    {
        return $user->hasPermission('insurance_catalog.review') && ! $this->edited($user, $version);
    }

    public function publish(User $user, InsurancePlanVersion $version): bool
    {
        return $user->hasPermission('insurance_catalog.publish') && ! $this->edited($user, $version);
    }

    private function edited(User $user, InsurancePlanVersion $version): bool
    {
        return DB::table('insurance_version_editors')->where('tenant_id', $version->tenant_id)
            ->where('insurance_plan_version_id', $version->id)->where('user_id', $user->id)->exists();
    }
}
