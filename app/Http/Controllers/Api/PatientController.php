<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Patient::class);

        $validated = $request->validate([
            'archived' => ['sometimes', Rule::in(['without', 'with', 'only'])],
        ]);

        $patients = Patient::query()
            ->when(($validated['archived'] ?? 'without') === 'without', fn ($query) => $query->active())
            ->when(($validated['archived'] ?? null) === 'only', fn ($query) => $query->archived())
            ->get();

        return ApiResponse::success([
            'patients' => PatientResource::collection($patients),
        ], 'Patients retrieved.');
    }

    public function store(StorePatientRequest $request)
    {
        $this->authorize('create', Patient::class);

        $patient = Patient::create($request->validated());

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient)],
            message: 'Patient successfully created.',
            statusCode: 201,
        );
    }

    public function show(Patient $patient)
    {
        $this->authorize('view', $patient);

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient)],
            message: 'Patient retrieved.',
        );
    }

    public function update(UpdatePatientRequest $request, Patient $patient)
    {
        $this->authorize('update', $patient);

        $patient->update($request->validated());

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient->refresh())],
            message: 'Patient successfully updated.',
        );
    }

    public function destroy(Patient $patient)
    {
        $this->authorize('delete', $patient);

        $patient->archive(request()->user());

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient->refresh())],
            message: 'Patient successfully archived.',
        );
    }
}
