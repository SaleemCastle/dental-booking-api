<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request)
    {
        $validated = $request->validated();
        $query = AuditLog::query()->with('actor:id,name');

        foreach (['actor_id', 'action', 'resource_type', 'resource_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $query->where($field, $validated[$field]);
            }
        }

        if (array_key_exists('actor_is_null', $validated)) {
            if ($request->boolean('actor_is_null')) {
                $query->whereNull('actor_id');
            } else {
                $query->whereNotNull('actor_id');
            }
        }

        if (isset($validated['request_id'])) {
            $query->where('request_metadata->request_id', $validated['request_id']);
        }

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($validated[$field])) {
                $query->where('occurred_at', $operator, CarbonImmutable::parse($validated[$field])->utc()->format('Y-m-d H:i:s.u'));
            }
        }

        $logs = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success([
            'auditLogs' => AuditLogResource::collection($logs->getCollection()),
        ], 'Audit logs retrieved.', meta: [
            'pagination' => [
                'currentPage' => $logs->currentPage(),
                'perPage' => $logs->perPage(),
                'total' => $logs->total(),
                'lastPage' => $logs->lastPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'hasMorePages' => $logs->hasMorePages(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function filters()
    {
        $this->authorize('viewAny', AuditLog::class);

        return ApiResponse::success([
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'resourceTypes' => AuditLog::query()->distinct()->orderBy('resource_type')->pluck('resource_type'),
        ], 'Audit filter options retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function show(AuditLog $auditLog)
    {
        $this->authorize('view', $auditLog);

        return ApiResponse::success([
            'auditLog' => new AuditLogResource($auditLog->load('actor:id,name')),
        ], 'Audit log retrieved.')->header('Cache-Control', 'private, no-store');
    }
}
