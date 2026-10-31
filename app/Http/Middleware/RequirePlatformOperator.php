<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class RequirePlatformOperator
{
    public function handle(Request $request, Closure $next)
    {
        // Always read the current grant; clinic roles and cached token claims cannot grant it.
        abort_unless(User::whereKey($request->user()?->id)->where('is_platform_operator', true)->exists(), 403);

        return $next($request);
    }
}
