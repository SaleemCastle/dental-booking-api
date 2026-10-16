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
    private const GENERAL_UPDATE_FIELDS = [
        'firstName',
        'lastName',
        'appointments',
        'sex',
        'streetAddress',
        'town',
        'city',
        'notes',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
    ];

    private const MEDICAL_UPDATE_FIELDS = [
        'allergies',
        'medications',
        'medical_alerts',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Patient::class);

        $validated = $request->validate([
            'archived' => ['sometimes', Rule::in(['without', 'with', 'only'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $patients = Patient::query()
            ->when(($validated['archived'] ?? 'without') === 'without', fn ($query) => $query->active())
            ->when(($validated['archived'] ?? null) === 'only', fn ($query) => $query->archived())
            ->when(filled($validated['search'] ?? null), fn ($query) => $query->search($validated['search']))
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ApiResponse::success([
            'patients' => PatientResource::collection($patients->getCollection()),
        ], 'Patients retrieved.', meta: [
            'pagination' => [
                'currentPage' => $patients->currentPage(),
                'perPage' => $patients->perPage(),
                'total' => $patients->total(),
                'lastPage' => $patients->lastPage(),
                'from' => $patients->firstItem(),
                'to' => $patients->lastItem(),
                'hasMorePages' => $patients->hasMorePages(),
            ],
        ]);
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
        $validated = $request->validated();

        if ($this->containsAnyField($validated, self::GENERAL_UPDATE_FIELDS) || $validated === []) {
            $this->authorize('update', $patient);
        }

        if ($this->containsAnyField($validated, self::MEDICAL_UPDATE_FIELDS)) {
            $this->authorize('updateMedical', $patient);
        }

        $patient->update($validated);

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient->refresh())],
            message: 'Patient successfully updated.',
        );
    }

    public function destroy(Patient $patient)
    {
        $this->authorize('archive', $patient);

        $patient->archive(request()->user());

        return ApiResponse::success(
            data: ['patient' => new PatientResource($patient->refresh())],
            message: 'Patient successfully archived.',
        );
    }

    private function containsAnyField(array $data, array $fields): bool
    {
        return collect($fields)->contains(fn (string $field) => array_key_exists($field, $data));
    }
}
