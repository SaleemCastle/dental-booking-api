<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $tenant = $this->resolver->resolve($user, $request->header('X-Tenant-Id'));

        if ($request->exists('tenant_id') || $request->exists('tenantId')) {
            throw ValidationException::withMessages(['tenant_id' => ['Clinic ownership is selected by authenticated context, not request fields.']]);
        }

        return $this->context->run($tenant, function () use ($request, $next, $tenant, $user): Response {
            $user->unsetRelation('roles');
            $hadTenantAttribute = $request->attributes->has('tenant_id');
            $previousTenantAttribute = $request->attributes->get('tenant_id');
            $request->attributes->set('tenant_id', $tenant->id);

            try {
                $response = $next($request);
                $response->headers->set('X-Tenant-Id', (string) $tenant->id);

                return $response;
            } finally {
                $user->unsetRelation('roles');
                if ($hadTenantAttribute) {
                    $request->attributes->set('tenant_id', $previousTenantAttribute);
                } else {
                    $request->attributes->remove('tenant_id');
                }
            }
        });
    }
}
