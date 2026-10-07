<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicalNoteResource;
use App\Models\ClinicalNote;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'clinicalNotes' => ClinicalNoteResource::collection(ClinicalNote::all()),
        ], 'Clinical notes retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['required', 'integer', 'exists:dentist,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointment,id'],
            'note_text' => ['required', 'string'],
        ]);

        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource(ClinicalNote::create($validated)),
        ], 'Clinical note successfully created.', 201);
    }

    public function show(ClinicalNote $clinicalNote)
    {
        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource($clinicalNote),
        ], 'Clinical note retrieved.');
    }

    public function update(Request $request, ClinicalNote $clinicalNote)
    {
        $validated = $request->validate([
            'patient_id' => ['sometimes', 'required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['sometimes', 'required', 'integer', 'exists:dentist,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointment,id'],
            'note_text' => ['sometimes', 'required', 'string'],
        ]);

        $clinicalNote->update($validated);

        return ApiResponse::success([
            'clinicalNote' => new ClinicalNoteResource($clinicalNote->refresh()),
        ], 'Clinical note successfully updated.');
    }

    public function destroy(ClinicalNote $clinicalNote)
    {
        $clinicalNote->delete();

        return ApiResponse::success(message: 'Clinical note successfully deleted.');
    }
}
