<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientDocumentRequest;
use App\Http\Resources\PatientDocumentResource;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Services\AuditLogService;
use App\Services\PatientDocumentWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientDocumentController extends Controller
{
    public function index(Request $request, Patient $patient, AuditLogService $auditLogs)
    {
        $this->authorize('viewAny', PatientDocument::class);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $patient->documents();
        $status = $data['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }
        $documents = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $auditLogs->record('listed', 'patient_documents', $patient->id, $request->user()->id);

        return ApiResponse::success(['documents' => PatientDocumentResource::collection($documents->getCollection())], 'Patient documents retrieved.', meta: ['pagination' => [
            'currentPage' => $documents->currentPage(), 'perPage' => $documents->perPage(), 'total' => $documents->total(),
            'lastPage' => $documents->lastPage(), 'from' => $documents->firstItem(), 'to' => $documents->lastItem(), 'hasMorePages' => $documents->hasMorePages(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function store(StorePatientDocumentRequest $request, Patient $patient, PatientDocumentWorkflow $workflow)
    {
        $document = $workflow->upload($patient, $request->validated(), $request->user());

        return ApiResponse::success(['document' => new PatientDocumentResource($document)], 'Patient document uploaded.', 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Patient $patient, PatientDocument $document, PatientDocumentWorkflow $workflow)
    {
        $document = $workflow->metadata($patient, $document, $request->user());

        return ApiResponse::success(['document' => new PatientDocumentResource($document)], 'Patient document retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, Patient $patient, PatientDocument $document, PatientDocumentWorkflow $workflow)
    {
        [$document, $stream] = $workflow->download($patient, $document, $request->user());

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) $document->size_bytes,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function archive(Request $request, Patient $patient, PatientDocument $document, PatientDocumentWorkflow $workflow)
    {
        $document = $workflow->archive($patient, $document, $request->user());

        return ApiResponse::success(['document' => new PatientDocumentResource($document)], 'Patient document archived.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, Patient $patient, PatientDocument $document, PatientDocumentWorkflow $workflow)
    {
        $workflow->delete($patient, $document, $request->user());

        return ApiResponse::success(message: 'Patient document deleted.')->header('Cache-Control', 'private, no-store');
    }
}
