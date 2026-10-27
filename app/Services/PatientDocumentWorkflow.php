<?php

namespace App\Services;

use App\Documents\DocumentStorage;
use App\Documents\DocumentUpload;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class PatientDocumentWorkflow
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogService $auditLogs,
        private readonly TenantContext $context,
    ) {}

    public function upload(Patient $patient, array $input, User $actor): PatientDocument
    {
        $tenantId = $this->context->requireId();
        Gate::forUser($actor)->authorize('create', PatientDocument::class);
        $patient = Patient::query()->findOrFail($patient->id);
        $data = DocumentUpload::validate($input);
        $file = $data['file'];
        $document = new PatientDocument;
        $document->forceFill([
            'patient_id' => $patient->id,
            'appointment_id' => $data['appointment_id'] ?? null,
            'clinical_note_id' => $data['clinical_note_id'] ?? null,
            'disk' => $this->storage->diskName(),
            'storage_key' => 'tenants/'.$tenantId.'/patients/'.$patient->id.'/'.Str::uuid(),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => DocumentUpload::mimeType($file),
            'size_bytes' => filesize($file->getRealPath()),
            'uploaded_by_user_id' => $actor->id,
        ]);
        $this->validateLinks($document);

        try {
            $this->storage->put($document->storage_key, $file);

            // No transaction retry around external side effects.
            return DB::transaction(function () use ($document, $actor): PatientDocument {
                Patient::query()->lockForUpdate()->findOrFail($document->patient_id);
                $this->validateLinks($document, true);
                $document->save();
                $this->auditLogs->record('uploaded', 'patient_documents', $document->id, $actor->id);

                return $document;
            });
        } catch (Throwable $error) {
            try {
                $this->storage->delete($document);
            } catch (Throwable) {
                // Object inventories can reconcile unreferenced UUID keys; no PHI in logs.
                Log::error('Patient document upload cleanup failed.', ['tenant_id' => $tenantId]);
            }
            if ($error instanceof FilesystemException) {
                throw new ServiceUnavailableHttpException;
            }
            throw $error;
        }
    }

    public function archive(Patient $patient, PatientDocument $document, User $actor): PatientDocument
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('archive', $document);

        return DB::transaction(function () use ($patient, $document, $actor): PatientDocument {
            $document = $this->find($patient, $document, true);
            if ($document->archived_at === null) {
                $document->forceFill(['archived_at' => now('UTC'), 'archived_by_user_id' => $actor->id])->save();
                $this->auditLogs->record('archived', 'patient_documents', $document->id, $actor->id);
            }

            return $document;
        });
    }

    public function delete(Patient $patient, PatientDocument $document, User $actor): void
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('delete', $document);
        // Commit the access revocation and its audit BEFORE touching object storage.
        // If S3 is down or a process dies, a repeated DELETE safely retries the purge.
        $document = DB::transaction(function () use ($patient, $document, $actor): PatientDocument {
            $document = $this->find($patient, $document, true, true);
            if (! $document->trashed()) {
                $document->forceFill(['deleted_by_user_id' => $actor->id, 'deleted_at' => now('UTC')])->save();
                $this->auditLogs->record('deleted', 'patient_documents', $document->id, $actor->id);
            }

            return $document;
        });
        if ($document->purged_at !== null) {
            return;
        }
        try {
            $this->storage->delete($document);
        } catch (FilesystemException) {
            throw new ServiceUnavailableHttpException;
        }
        DB::transaction(function () use ($patient, $document, $actor): void {
            $document = $this->find($patient, $document, true, true);
            if ($document->purged_at === null) {
                $document->forceFill(['purged_at' => now('UTC')])->save();
                $this->auditLogs->record('purged', 'patient_documents', $document->id, $actor->id);
            }
        });
    }

    public function metadata(Patient $patient, PatientDocument $document, User $actor): PatientDocument
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('view', $document);
        $document = $this->find($patient, $document);
        $this->auditLogs->record('viewed', 'patient_documents', $document->id, $actor->id);

        return $document;
    }

    /** @return array{PatientDocument, resource} */
    public function download(Patient $patient, PatientDocument $document, User $actor): array
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('download', $document);
        $stream = null;
        try {
            return DB::transaction(function () use ($patient, $document, $actor, &$stream): array {
                $document = $this->find($patient, $document, true);
                $stream = $this->storage->read($document);
                // A download event means authorized stream opened, not proof of receipt.
                $this->auditLogs->record('downloaded', 'patient_documents', $document->id, $actor->id);

                return [$document, $stream];
            });
        } catch (Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($error instanceof FilesystemException) {
                throw new ServiceUnavailableHttpException;
            }
            throw $error;
        }
    }

    private function find(Patient $patient, PatientDocument $document, bool $lock = false, bool $withDeleted = false): PatientDocument
    {
        Patient::query()->findOrFail($patient->id);
        $query = PatientDocument::query()->where('patient_id', $patient->id);
        if ($withDeleted) {
            $query->withTrashed();
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($document->id);
    }

    private function validateLinks(PatientDocument $document, bool $lock = false): void
    {
        $appointment = $document->appointment_id === null ? null : Appointment::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($document->appointment_id);
        $note = $document->clinical_note_id === null ? null : ClinicalNote::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($document->clinical_note_id);
        $errors = [];
        if ($appointment !== null && (int) $appointment->patient_id !== $document->patient_id) {
            $errors['appointment_id'] = ['The appointment must belong to this patient.'];
        }
        if ($note !== null && ($note->patient_id !== $document->patient_id
            || ($appointment !== null && $note->appointment_id !== null && $note->appointment_id !== $appointment->id))) {
            $errors['clinical_note_id'] = ['The note must belong to this patient and match the linked appointment when assigned.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
