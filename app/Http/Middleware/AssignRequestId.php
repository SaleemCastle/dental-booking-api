<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->headers->get('X-Request-Id');

        if (is_string($requestId) && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $requestId)) {
            return $requestId;
        }

        return (string) Str::uuid();
    }
}
