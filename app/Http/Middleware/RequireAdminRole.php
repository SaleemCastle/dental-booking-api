<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->load('roles')->hasRole(Role::ADMIN)) {
            throw new AuthorizationException('Only administrators may manage roles or permissions.');
        }

        return $next($request);
    }
}
