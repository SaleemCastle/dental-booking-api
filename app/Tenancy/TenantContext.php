<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use LogicException;

class TenantContext
{
    private ?int $tenantId = null;

    private ?int $defaultId = null;

    public function id(): int
    {
        return $this->tenantId ?? ($this->defaultId ??= Tenant::where('slug', Tenant::DEFAULT_SLUG)->value('id')
            ?? throw new LogicException('The default clinic has not been migrated.'));
    }

    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenant->id;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
