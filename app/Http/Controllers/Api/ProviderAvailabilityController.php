<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProviderAvailabilityRequest;
use App\Models\Dentist;
use App\Models\ProviderAvailability;
use App\Services\ProviderAvailabilityService;
use App\Support\ApiResponse;

class ProviderAvailabilityController extends Controller
{
    public function show(Dentist $dentist)
    {
        $this->authorize('viewAny', ProviderAvailability::class);

        return $this->response($dentist, ProviderAvailability::where('dentist_id', $dentist->id)->first());
    }

    public function update(UpdateProviderAvailabilityRequest $request, Dentist $dentist, ProviderAvailabilityService $service)
    {
        return $this->response($dentist, $service->replace($dentist, $request->validated(), $request->user()), 'Provider availability updated.');
    }

    private function response(Dentist $dentist, ?ProviderAvailability $availability, string $message = 'Provider availability retrieved.')
    {
        return ApiResponse::success(['availability' => [
            'dentist_id' => $dentist->id,
            'configured' => $availability !== null,
            'timezone' => config('app.timezone'),
            'weekly_hours' => $availability?->weekly_hours ?? [],
            'breaks' => $availability?->breaks ?? [],
            'exceptions' => $availability?->exceptions ?? [],
        ]], $message);
    }
}
