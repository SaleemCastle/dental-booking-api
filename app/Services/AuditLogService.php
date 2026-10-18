<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use InvalidArgumentException;

class AuditLogService
{
    public function record(string $action, string $resourceType, string|int $resourceId, ?int $actorId, ?Request $request = null): AuditLog
    {
        if (! preg_match('/\A[a-z][a-z0-9_.]{0,79}\z/', $action)
            || ! preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $resourceType)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', (string) $resourceId)
            || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('Audit entries require machine-readable action, resource type, resource ID, and a positive actor ID or null.');
        }

        if ($request === null && app()->bound('request')) {
            $currentRequest = app('request');
            $request = $currentRequest->route() instanceof Route ? $currentRequest : null;
        }

        $requestId = $request?->attributes->get('request_id');
        $route = $request?->route();
        $userAgent = $request?->userAgent();
        $ipAddress = $request?->ip();

        $entry = new AuditLog;
        $entry->forceFill([
            'actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => (string) $resourceId,
            'occurred_at' => now('UTC'),
            'ip_address' => filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null,
            'user_agent' => $userAgent === null ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent) ?? '', 0, 512),
            'request_metadata' => $request === null ? null : array_filter([
                'request_id' => is_string($requestId) && preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $requestId) ? $requestId : null,
                'method' => mb_substr($request->method(), 0, 16),
                'route' => $route instanceof Route ? mb_substr($route->uri(), 0, 255) : null,
            ], fn ($value) => $value !== null),
        ])->save();

        return $entry;
    }
}
