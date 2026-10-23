<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClinicalNoteRequest;
use App\Http\Requests\UpdateClinicalNoteRequest;
use App\Http\Resources\ClinicalNoteResource;
use App\Models\ClinicalNote;
use App\Services\ClinicalNoteWorkflow;
use App\Support\ApiResponse;
use App\Tenancy\TenantRules;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ClinicalNote::class);

        $validated = $request->validate([
            'patient_id' => ['sometimes', 'integer', TenantRules::exists('patient')],
            'dentist_id' => ['sometimes', 'integer', TenantRules::exists('dentist')],
            'appointment_id' => ['sometimes', 'integer', TenantRules::exists('appointment')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ClinicalNote::query()->with(['author', 'lastEditor']);

        foreach (['patient_id', 'dentist_id', 'appointment_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        $notes = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success([
            'clinicalNotes' => ClinicalNoteResource::collection($notes->getCollection()),
        ], 'Clinical notes retrieved.', meta: [
            'pagination' => [
                'currentPage' => $notes->currentPage(),
                'perPage' => $notes->perPage(),
                'total' => $notes->total(),
                'lastPage' => $notes->lastPage(),
                'from' => $notes->firstItem(),
                'to' => $notes->lastItem(),
                'hasMorePages' => $notes->hasMorePages(),
            ],
        ]);
    }

    public function store(StoreClinicalNoteRequest $request, ClinicalNoteWorkflow $workflow)
    {
        $note = $workflow->create($request->validated(), $request->user());

        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource($note->load(['author', 'lastEditor'])),
        ], 'Clinical note successfully created.', 201);
    }

    public function show(ClinicalNote $clinicalNote)
    {
        $this->authorize('view', $clinicalNote);

        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource($clinicalNote->load(['author', 'lastEditor'])),
        ], 'Clinical note retrieved.');
    }

    public function update(UpdateClinicalNoteRequest $request, ClinicalNote $clinicalNote, ClinicalNoteWorkflow $workflow)
    {
        $note = $workflow->update($clinicalNote, $request->validated(), $request->user());

        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource($note->load(['author', 'lastEditor'])),
        ], 'Clinical note successfully updated.');
    }

    public function destroy(Request $request, ClinicalNote $clinicalNote, ClinicalNoteWorkflow $workflow)
    {
        $this->authorize('delete', $clinicalNote);
        $workflow->delete($clinicalNote, $request->user());

        return ApiResponse::success(message: 'Clinical note successfully deleted.');
    }
}
