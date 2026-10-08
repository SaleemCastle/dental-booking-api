<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TreatmentResource;
use App\Models\Treatment;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TreatmentController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'treatments' => TreatmentResource::collection(Treatment::all()),
        ], 'Treatments retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        return ApiResponse::success([
            'treatment' => new TreatmentResource(Treatment::create($validated)),
        ], 'Treatment successfully created.', 201);
    }

    public function show(Treatment $treatment)
    {
        return ApiResponse::success([
            'treatment' => new TreatmentResource($treatment),
        ], 'Treatment retrieved.');
    }

    public function update(Request $request, Treatment $treatment)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $treatment->update($validated);

        return ApiResponse::success([
            'treatment' => new TreatmentResource($treatment->refresh()),
        ], 'Treatment successfully updated.');
    }

    public function destroy(Treatment $treatment)
    {
        $treatment->delete();

        return ApiResponse::success(message: 'Treatment successfully deleted.');
    }
}
