<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Support\ApiResponse;

class PatientController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Patient::class);

        return ApiResponse::success([
            'patients' => PatientResource::collection(Patient::all()),
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

        $patient->delete();

        return ApiResponse::success(message: 'Patient successfully deleted.');
    }
}
