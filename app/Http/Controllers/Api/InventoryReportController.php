<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryReportingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryReportController extends Controller
{
    public function __construct(private readonly InventoryReportingService $reports) {}

    public function lowStock(Request $request): JsonResponse
    {
        return $this->respond($this->reports->lowStock($request->query(), $request->user()));
    }

    public function expiry(Request $request): JsonResponse
    {
        return $this->respond($this->reports->expiry($request->query(), $request->user()));
    }

    private function respond(array $report): JsonResponse
    {
        return ApiResponse::success($report['page'], meta: ['criteria' => $report['criteria']])
            ->header('Cache-Control', 'private, no-store');
    }
}
