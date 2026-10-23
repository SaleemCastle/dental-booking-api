<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-Id');
        if ($tenantId !== null && (! preg_match('/\A[1-9][0-9]*\z/', $tenantId) || strlen($tenantId) > 18)) {
            throw ValidationException::withMessages(['tenant_id' => ['X-Tenant-Id must be a positive clinic ID.']]);
        }
        $tenant = $tenantId === null
            ? Tenant::where('slug', Tenant::DEFAULT_SLUG)->first()
            : Tenant::find($tenantId);

        if (! $tenant || ! $tenant->active || ! $request->user()->tenants()->whereKey($tenant->id)->exists()) {
            throw new AuthorizationException('Current clinic membership is required.');
        }
        if ($request->exists('tenant_id') || $request->exists('tenantId')) {
            throw ValidationException::withMessages(['tenant_id' => ['Clinic ownership is selected by authenticated context, not request fields.']]);
        }

        return app(TenantContext::class)->run($tenant, function () use ($request, $next, $tenant): Response {
            $request->user()->unsetRelation('roles');
            $request->attributes->set('tenant_id', $tenant->id);
            $response = $next($request);
            $response->headers->set('X-Tenant-Id', (string) $tenant->id);

            return $response;
        });
    }
}
