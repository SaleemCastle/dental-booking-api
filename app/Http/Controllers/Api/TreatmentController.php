<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TreatmentResource;
use App\Models\Treatment;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TreatmentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Treatment::class);

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        $treatments = Treatment::query()
            ->when(array_key_exists('active', $validated), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'treatments' => TreatmentResource::collection($treatments),
        ], 'Treatments retrieved.');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Treatment::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success([
            'treatment' => new TreatmentResource(Treatment::create($validated)->refresh()),
        ], 'Treatment successfully created.', 201);
    }

    public function show(Treatment $treatment)
    {
        $this->authorize('view', $treatment);

        return ApiResponse::success([
            'treatment' => new TreatmentResource($treatment),
        ], 'Treatment retrieved.');
    }

    public function update(Request $request, Treatment $treatment)
    {
        $this->authorize('update', $treatment);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $treatment->update($validated);

        return ApiResponse::success([
            'treatment' => new TreatmentResource($treatment->refresh()),
        ], 'Treatment successfully updated.');
    }

    public function destroy(Treatment $treatment)
    {
        $this->authorize('delete', $treatment);

        $treatment->update(['active' => false]);

        return ApiResponse::success(message: 'Treatment successfully deactivated.');
    }
}
