<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Patient::class);

        return ApiResponse::success([
            'patients' => PatientResource::collection(Patient::all()),
        ], 'Patients retrieved.');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Patient::class);

        $validated = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'appointments' => ['required', 'string', 'max:255'],
            'sex' => ['required', 'string', 'max:255'],
            'streetAddress' => ['required', 'string', 'max:255'],
            'town' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'notes' => ['required', 'string'],
        ]);

        $patient = Patient::create($validated);

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

    public function update(Request $request, Patient $patient)
    {
        $this->authorize('update', $patient);

        $validated = $request->validate([
            'firstName' => ['sometimes', 'required', 'string', 'max:100'],
            'lastName' => ['sometimes', 'required', 'string', 'max:100'],
            'appointments' => ['sometimes', 'required', 'string', 'max:255'],
            'sex' => ['sometimes', 'required', 'string', 'max:255'],
            'streetAddress' => ['sometimes', 'required', 'string', 'max:255'],
            'town' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'required', 'string'],
        ]);

        $patient->update($validated);

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
