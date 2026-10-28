<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Models\ProviderAvailability;
use App\Services\ReportingService;
use App\Support\ApiResponse;

class ReportController extends Controller
{
    public function appointments(ReportRequest $request, ReportingService $reports)
    {
        return $this->respond($request, $reports->appointments($request->validated()));
    }

    public function providers(ReportRequest $request, ReportingService $reports)
    {
        return $this->respond($request, $reports->providers(
            $request->validated(), $request->user()->can('viewAny', ProviderAvailability::class),
        ));
    }

    public function treatments(ReportRequest $request, ReportingService $reports)
    {
        return $this->respond($request, $reports->treatments($request->validated()));
    }

    public function billing(ReportRequest $request, ReportingService $reports)
    {
        return $this->respond($request, $reports->billing($request->validated()));
    }

    private function respond(ReportRequest $request, mixed $data)
    {
        return ApiResponse::success($data, 'Report retrieved.', meta: [
            'filters' => [
                'date_from' => $request->validated('date_from'),
                'date_to' => $request->validated('date_to'),
                'dentist_id' => $request->has('dentist_id') ? (int) $request->validated('dentist_id') : null,
            ],
            'timezone' => config('app.timezone'),
        ])->header('Cache-Control', 'private, no-store');
    }
}
