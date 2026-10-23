<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class TenantRules
{
    public static function exists(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->id();

        if ($table === 'users') {
            return Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId);
        }

        return Rule::exists($table, 'id')->where('tenant_id', $tenantId);
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->id());
    }
}
