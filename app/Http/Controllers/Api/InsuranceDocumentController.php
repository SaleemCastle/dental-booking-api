<?php

namespace App\Http\Controllers\Api;

use App\Documents\DocumentProtection;
use App\Http\Controllers\Controller;
use App\Http\Resources\InsuranceDocumentResource;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Services\InsuranceDocumentWorkflow;
use App\Services\PatientDocumentWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InsuranceDocumentController extends Controller
{
    public function index(Request $request, InsuranceDocumentWorkflow $workflow, ?Patient $patient = null)
    {
        $page = $workflow->listing($patient, $request->query(), $request->user());
        $page->setCollection($page->getCollection()->map(fn ($d) => new InsuranceDocumentResource($d)));

        return ApiResponse::success($page)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, InsuranceDocumentWorkflow $workflow, ?Patient $patient = null)
    {
        return $this->record($workflow->upload($patient, $request->all(), $request->user()), 201);
    }

    public function clinicalVersion(Request $request, Patient $patient, PatientDocument $document, InsuranceDocumentWorkflow $workflow)
    {
        return $this->record($workflow->clinicalVersion($patient, $document, $request->all(), $request->user()), 201);
    }

    public function version(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow)
    {
        return $this->record($workflow->upload(null, $request->all(), $request->user(), $document), 201);
    }

    public function show(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow, PatientDocumentWorkflow $documents)
    {
        $document = $workflow->document($document, $request->user());

        return $this->record($documents->metadata($document->patient, $document, $request->user()));
    }

    public function download(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow, PatientDocumentWorkflow $documents)
    {
        $document = $workflow->document($document, $request->user());
        [$document, $stream] = $documents->download($document->patient, $document, $request->user());

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $document->original_filename, ['Content-Type' => $document->mime_type, 'Content-Length' => (string) $document->size_bytes,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function archive(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow, PatientDocumentWorkflow $documents)
    {
        $document = $workflow->document($document, $request->user());

        return $this->record($documents->archive($document->patient, $document, $request->user()));
    }

    public function destroy(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow, PatientDocumentWorkflow $documents)
    {
        $document = $workflow->document($document, $request->user());
        $documents->delete($document->patient, $document, $request->user());

        return ApiResponse::success()->header('Cache-Control', 'private, no-store');
    }

    public function link(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow)
    {
        $link = $workflow->link($document, $request->all(), $request->user());

        return ApiResponse::success(['link' => ['id' => $link->id, 'version_id' => $link->version_id, 'patient_id' => $link->patient_id, 'plan_version_id' => $link->plan_version_id]], statusCode: 201)->header('Cache-Control', 'private, no-store');
    }

    public function hold(Request $request, PatientDocument $document, InsuranceDocumentWorkflow $workflow)
    {
        $hold = $workflow->hold($document, $request->all(), $request->user());

        return ApiResponse::success(['hold' => ['id' => $hold->id, 'action' => $hold->action, 'release_of_id' => $hold->release_of_id]], statusCode: 201)->header('Cache-Control', 'private, no-store');
    }

    public function quarantine(Request $request, PatientDocument $document, DocumentProtection $protection)
    {
        if ($request->all() !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['request' => ['No quarantine fields are accepted.']]);
        }
        Gate::forUser($request->user())->authorize('quarantine', $document);
        $protection->quarantine($document, $request->user());

        return ApiResponse::success(message: 'Document quarantined.')->header('Cache-Control', 'private, no-store');
    }

    private function record(PatientDocument $document, int $status = 200)
    {
        return ApiResponse::success(['document' => new InsuranceDocumentResource($document)], statusCode: $status)->header('Cache-Control', 'private, no-store');
    }
}
