<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                if ($request->expectsJson()) {
                    return ApiResponse::error(
                        message: 'Already authenticated.',
                        statusCode: 409,
                        code: 'ALREADY_AUTHENTICATED',
                        request: $request,
                    );
                }

                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
