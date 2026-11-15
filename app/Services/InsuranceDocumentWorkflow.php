<?php

namespace App\Services;

use App\Documents\DocumentProtection;
use App\Documents\DocumentStorage;
use App\Documents\DocumentUpload;
use App\Exceptions\DocumentProtectionConflict;
use App\Models\DocumentSeries;
use App\Models\DocumentVersion;
use App\Models\InsurancePlanVersion;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class InsuranceDocumentWorkflow
{
    public function __construct(private readonly DocumentProtection $protection, private readonly DocumentStorage $storage, private readonly AuditLogService $audit, private readonly TenantContext $context) {}

    private function ability(User $actor, string $ability): void
    {
        $this->context->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission($ability), 403);
    }

    public function upload(?Patient $patient, array $input, User $actor, ?PatientDocument $previous = null): PatientDocument
    {
        return $this->store($patient, $input, $actor, $previous, false);
    }

    public function clinicalVersion(Patient $patient, PatientDocument $previous, array $input, User $actor): PatientDocument
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('create', PatientDocument::class);

        return $this->store($patient, $input, $actor, $previous, true);
    }

    private function store(?Patient $patient, array $input, User $actor, ?PatientDocument $previous, bool $clinicalMode): PatientDocument
    {
        if (! $clinicalMode) {
            $this->ability($actor, 'insurance_documents.upload');
            $this->ability($actor, 'insurance_documents.view');
            if (! config('insurance_evidence.uploads_enabled', false)) {
                throw new DocumentProtectionConflict('DOCUMENT_EVIDENCE_NOT_ACTIVATED');
            }
        }
        $series = null;
        if ($previous !== null) {
            $previous = $clinicalMode ? PatientDocument::query()->where('patient_id', $patient?->id)->findOrFail($previous->id) : $this->document($previous, $actor);
            Gate::forUser($actor)->authorize('view', $previous);
            $series = $this->protection->version($previous)->series;
            abort_if($clinicalMode && $series->classification !== 'clinical', 403);
            $patient = $previous->patient_id === null ? null : Patient::findOrFail($previous->patient_id);
        }
        if ($patient !== null) {
            $patient = Patient::findOrFail($patient->id);
            if (! $clinicalMode) {
                Gate::forUser($actor)->authorize('view', $patient);
            }
        }
        if ($patient === null) {
            $this->ability($actor, 'insurance_documents.tenant_upload');
            $this->ability($actor, 'insurance_documents.tenant_view');
        }
        $rules = [
            'file' => ['required'], 'command_key' => ['required', 'uuid'], 'provenance' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'classification' => ['sometimes', Rule::in(['insurance', 'mixed'])],
        ];
        if ($series !== null) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        }
        if ($clinicalMode) {
            unset($rules['classification']);
        }
        $data = $this->validate($input, $rules);
        $file = DocumentUpload::validate(['file' => $data['file']])['file'];
        $classification = $data['classification'] ?? $series?->classification ?? 'insurance';
        if ($classification !== 'insurance') {
            abort_unless($patient !== null && $this->protection->clinicalWrite($actor), 403);
        }
        abort_if($series !== null && $classification !== $series->classification, 422);
        $requestHash = hash('sha256', json_encode([$actor->id, $patient?->id, $series?->id, $classification, $data['provenance'], $data['expected_version'] ?? null, hash_file('sha256', $file->getRealPath()), $file->getClientOriginalName()], JSON_THROW_ON_ERROR));

        return $this->protection->fence('evidence-upload:'.$this->context->requireId().':'.$data['command_key'], function () use ($patient, $series, $data, $file, $actor, $classification, $requestHash, $clinicalMode, $previous): PatientDocument {
            $existing = DocumentVersion::query()->where('command_key', $data['command_key'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                }
                $document = PatientDocument::query()->findOrFail($existing->document_id);
                Gate::forUser($actor)->authorize('view', $document);
                $this->audit->record('upload_replayed', 'patient_documents', $document->id, $actor->id);

                return $document;
            }
            $document = new PatientDocument;
            $document->forceFill([
                'tenant_id' => $this->context->requireId(), 'patient_id' => $patient?->id,
                'appointment_id' => $classification === 'clinical' ? $previous?->appointment_id : null,
                'clinical_note_id' => $classification === 'clinical' ? $previous?->clinical_note_id : null,
                'disk' => $this->storage->diskName(), 'storage_key' => 'tenants/'.$this->context->requireId().($clinicalMode ? '/patients/'.$patient->id : '/evidence').'/'.Str::uuid(),
                'original_filename' => $file->getClientOriginalName(), 'mime_type' => DocumentUpload::mimeType($file),
                'size_bytes' => filesize($file->getRealPath()), 'uploaded_by_user_id' => $actor->id,
            ]);
            try {
                $this->storage->put($document->storage_key, $file);

                return DB::transaction(function () use ($document, $series, $data, $file, $actor, $classification, $requestHash, $clinicalMode, $previous): PatientDocument {
                    if ($clinicalMode) {
                        Gate::forUser($actor)->authorize('create', PatientDocument::class);
                    } else {
                        $this->ability($actor, 'insurance_documents.upload');
                        $this->ability($actor, 'insurance_documents.view');
                    }
                    if ($document->patient_id === null) {
                        $this->ability($actor, 'insurance_documents.tenant_upload');
                        $this->ability($actor, 'insurance_documents.tenant_view');
                    }
                    if ($classification !== 'insurance') {
                        abort_unless($this->protection->clinicalWrite($actor), 403);
                    }
                    if ($document->patient_id !== null) {
                        $patient = Patient::query()->lockForUpdate()->findOrFail($document->patient_id);
                        if (! $clinicalMode) {
                            Gate::forUser($actor)->authorize('view', $patient);
                        }
                    }
                    if ($series !== null) {
                        $series = DocumentSeries::query()->lockForUpdate()->findOrFail($series->id);
                        // A link may have added purpose/target restrictions while storage was pending.
                        Gate::forUser($actor)->authorize('view', PatientDocument::query()->findOrFail($previous->id));
                        $latest = DocumentVersion::query()->where('series_id', $series->id)->orderByDesc('version')->firstOrFail();
                        if ($latest->version !== (int) $data['expected_version']) {
                            throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                        }
                        if (DB::table('document_versions as v')->join('document_protections as p', 'p.version_id', '=', 'v.id')->join('patient_documents as stored', 'stored.id', '=', 'v.document_id')->where('v.series_id', $series->id)->where(fn ($q) => $q->where('p.purge_state', '<>', 'retained')->orWhereNotNull('stored.deleted_at'))->exists()) {
                            throw new DocumentProtectionConflict;
                        }
                    }
                    $document->save();
                    $this->protection->register($document, $file, $classification, $series, $data['provenance'], $data['command_key'], $requestHash);
                    $this->audit->record('version_uploaded', 'patient_documents', $document->id, $actor->id);

                    return $document;
                });
            } catch (Throwable $error) {
                try {
                    $this->storage->delete($document);
                } catch (Throwable) {
                    Log::error('Evidence upload cleanup requires private object inventory.', ['tenant_id' => $this->context->requireId()]);
                }
                if ($error instanceof FilesystemException) {
                    throw new ServiceUnavailableHttpException;
                }
                throw $error;
            }
        });
    }

    public function document(PatientDocument $document, User $actor): PatientDocument
    {
        $this->ability($actor, 'insurance_documents.view');
        $document = PatientDocument::withTrashed()->findOrFail($document->id);
        $version = $this->protection->version($document);
        abort_unless($version->series->classification !== 'clinical' || DB::table('document_evidence_links')->whereIn('version_id', DocumentVersion::query()->where('series_id', $version->series_id)->select('id'))->exists(), 404);
        Gate::forUser($actor)->authorize('view', $document);

        return $document;
    }

    public function listing(?Patient $patient, array $input, User $actor)
    {
        $this->ability($actor, 'insurance_documents.view');
        if ($patient !== null) {
            $patient = Patient::findOrFail($patient->id);
            Gate::forUser($actor)->authorize('view', $patient);
        }
        if ($patient === null) {
            $this->ability($actor, 'insurance_documents.tenant_view');
        }
        $data = $this->validate($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $query = $this->protection->visible(PatientDocument::query()->where('patient_id', $patient?->id)->whereNull('archived_at'), $actor, $patient);
        $query->whereHas('versionRecord', fn ($q) => $q->whereHas('series', fn ($s) => $s->where('classification', '<>', 'clinical'))->orWhereExists(fn ($l) => $l->selectRaw('1')->from('document_evidence_links')->join('document_versions as linked_version', 'linked_version.id', '=', 'document_evidence_links.version_id')->whereColumn('linked_version.series_id', 'document_versions.series_id')));
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $this->audit->record('listed', 'insurance_documents', $patient?->id ?? $this->context->requireId(), $actor->id);

        return $page;
    }

    public function link(PatientDocument $document, array $input, User $actor): object
    {
        $this->ability($actor, 'insurance_documents.link');
        if (! config('insurance_evidence.linking_enabled', false)) {
            throw new DocumentProtectionConflict('DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        }
        $this->ability($actor, 'insurance_documents.view');
        $data = $this->validate($input, [
            'command_key' => ['required', 'uuid'], 'patient_id' => ['sometimes', 'integer', 'min:1'], 'plan_version_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        if ((isset($data['patient_id']) ? 1 : 0) + (isset($data['plan_version_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['target' => ['Supply exactly one typed target.']]);
        }
        $target = isset($data['patient_id']) ? Patient::findOrFail($data['patient_id']) : InsurancePlanVersion::findOrFail($data['plan_version_id']);
        Gate::forUser($actor)->authorize('view', $target);
        Gate::forUser($actor)->authorize('view', $document);

        // Common order: series advisory fence -> business target -> series -> document.
        try {
            return $this->protection->seriesFence($document, fn () => DB::transaction(function () use ($document, $data, $actor): object {
                if (isset($data['patient_id'])) {
                    $target = Patient::query()->lockForUpdate()->findOrFail($data['patient_id']);
                } else {
                    $target = InsurancePlanVersion::query()->lockForUpdate()->findOrFail($data['plan_version_id']);
                }
                Gate::forUser($actor)->authorize('view', $target);
                $document = $this->protection->lock($document);
                $this->ability($actor, 'insurance_documents.link');
                Gate::forUser($actor)->authorize('view', $document);
                $this->protection->assertReferenceable($document);
                $this->protection->assertReadable($document);
                $version = $this->protection->version($document);
                if ((isset($data['patient_id']) && $document->patient_id !== $target->id) || (isset($data['plan_version_id']) && $document->patient_id !== null)) {
                    abort(404);
                }
                if ($version->scan_status !== 'clean' || $version->content_sha256 === null) {
                    throw new DocumentProtectionConflict('DOCUMENT_QUARANTINED');
                }
                // Open and verify bytes under the same parent lock, but do not transmit them.
                $stream = $this->protection->verifiedStream($document, $this->storage->read($document));
                fclose($stream);
                $values = [
                    'tenant_id' => $this->context->requireId(), 'version_id' => $version->id,
                    'patient_id' => $data['patient_id'] ?? null, 'plan_version_id' => $data['plan_version_id'] ?? null, 'actor_id' => $actor->id,
                ];
                $existing = DB::table('document_evidence_links')->where('tenant_id', $document->tenant_id)->where('command_key', $data['command_key'])->first();
                if ($existing !== null) {
                    foreach ($values as $key => $value) {
                        if ($value != $existing->$key) {
                            throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                        }
                    }

                    return $existing;
                }
                $existing = DB::table('document_evidence_links')->where('version_id', $version->id)->where('patient_id', $values['patient_id'])->where('plan_version_id', $values['plan_version_id'])->first();
                if ($existing !== null) {
                    throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                }
                $id = DB::table('document_evidence_links')->insertGetId([...$values, 'command_key' => $data['command_key']]);
                $this->audit->record('evidence_linked', 'document_evidence_links', $id, $actor->id);

                return DB::table('document_evidence_links')->find($id);
            }));
        } catch (Throwable $error) {
            if ($error instanceof DocumentProtectionConflict && $error->apiCode === 'DOCUMENT_QUARANTINED') {
                $this->protection->quarantineCorruptVersion($document, $actor);
            }
            if ($error instanceof FilesystemException) {
                throw new ServiceUnavailableHttpException;
            }
            throw $error;
        }
    }

    public function hold(PatientDocument $document, array $input, User $actor): object
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize('hold', $document);
        $data = $this->validate($input, [
            'command_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'release_of_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return $this->protection->seriesFence($document, fn () => DB::transaction(function () use ($document, $data, $actor): object {
            $document = $this->protection->lock($document);
            Gate::forUser($actor)->authorize('hold', $document);
            $this->protection->assertReferenceable($document);
            $version = $this->protection->version($document);
            $existing = DB::table('document_hold_events')->where('tenant_id', $document->tenant_id)->where('command_key', $data['command_key'])->first();
            if ($existing !== null) {
                if ($existing->series_id !== $version->series_id || $existing->actor_id !== $actor->id || $existing->release_of_id != ($data['release_of_id'] ?? null) || Crypt::decryptString($existing->reason) !== $data['reason']) {
                    throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                }

                return $existing;
            }
            if (isset($data['release_of_id'])) {
                $hold = DB::table('document_hold_events')->where('tenant_id', $document->tenant_id)->where('series_id', $version->series_id)->where('id', $data['release_of_id'])->where('action', 'placed')->first();
                abort_if($hold === null, 404);
                if (DB::table('document_hold_events')->where('release_of_id', $hold->id)->exists()) {
                    throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                }
            }
            $id = DB::table('document_hold_events')->insertGetId([
                'tenant_id' => $document->tenant_id, 'series_id' => $version->series_id, 'actor_id' => $actor->id,
                'command_key' => $data['command_key'], 'release_of_id' => $data['release_of_id'] ?? null,
                'action' => isset($data['release_of_id']) ? 'released' : 'placed', 'reason' => Crypt::encryptString($data['reason']),
            ]);
            $this->audit->record(isset($data['release_of_id']) ? 'hold_released' : 'hold_placed', 'document_hold_events', $id, $actor->id);

            return DB::table('document_hold_events')->find($id);
        }));
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => ['Unsupported evidence field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
