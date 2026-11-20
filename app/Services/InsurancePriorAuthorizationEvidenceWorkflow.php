<?php

namespace App\Services;

use App\Documents\DocumentProtection;
use App\Documents\DocumentStorage;
use App\Exceptions\DocumentProtectionConflict;
use App\Models\InsuranceClaim;
use App\Models\InsurancePriorAuthorization;
use App\Models\InsurancePriorAuthorizationDecision;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class InsurancePriorAuthorizationEvidenceWorkflow
{
    public function __construct(private readonly DocumentProtection $protection, private readonly DocumentStorage $storage, private readonly AuditLogService $audit, private readonly TenantContext $context) {}

    public function link(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): object
    {
        $patient = $this->targetAuth($patient, $authorization, $actor);
        $this->ability($actor, 'evidence');
        $this->ability($actor, 'manage');
        if (! config('insurance_evidence.linking_enabled', false)) {
            throw new DocumentProtectionConflict('DOCUMENT_EVIDENCE_NOT_ACTIVATED');
        }
        $data = Validator::make($input, ['document_id' => ['required', 'integer', 'min:1'], 'target_type' => ['required', 'in:authorization,decision,claim,submission'], 'target_id' => ['required', 'integer', 'min:1'], 'command_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($patient, $authorization, $data, $actor) {
            $target = $this->typedTarget($patient, $authorization, $data['target_type'], $data['target_id']);
            $document = PatientDocument::withTrashed()->where('patient_id', $patient->id)->findOrFail($data['document_id']);
            Gate::forUser($actor)->authorize('view', $document);

            return $this->protection->seriesFence($document, function () use ($document, $patient, $data, $actor, $target) {
                return DB::transaction(function () use ($document, $patient, $data, $actor, $target) {
                    $doc = $this->protection->lock($document);
                    Gate::forUser($actor)->authorize('view', $doc);
                    $this->protection->assertReferenceable($doc);
                    $this->protection->assertReadable($doc);
                    $version = $this->protection->version($doc);
                    if ($version->scan_status !== 'clean' || $version->content_sha256 === null) {
                        throw new DocumentProtectionConflict('DOCUMENT_QUARANTINED');
                    }
                    try {
                        $verified = $this->protection->verifiedStream($doc, $this->storage->read($doc));
                        fclose($verified);
                    } catch (DocumentProtectionConflict $error) {
                        if ($error->apiCode === 'DOCUMENT_QUARANTINED') {
                            $this->protection->quarantineCorruptVersion($doc, $actor);
                        }throw $error;
                    } catch (\League\Flysystem\FilesystemException) {
                        throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
                    }
                    $existing = DB::table('insurance_prior_authorization_evidence')->where('tenant_id', $this->context->requireId())->where('command_key', $data['command_key'])->first();
                    $values = ['tenant_id' => $this->context->requireId(), 'patient_id' => $patient->id, 'version_id' => $version->id, 'target_type' => $data['target_type'],
                        'authorization_id' => $data['target_type'] === 'authorization' ? $target->id : null, 'decision_id' => $data['target_type'] === 'decision' ? $target->id : null,
                        'claim_id' => $data['target_type'] === 'claim' ? $target->id : null, 'submission_id' => $data['target_type'] === 'submission' ? $target->id : null, 'actor_id' => $actor->id];
                    if ($existing) {
                        foreach ($values as $key => $value) {
                            if ($value != $existing->$key) {
                                throw new DocumentProtectionConflict('DOCUMENT_VERSION_CONFLICT');
                            }
                        }

return $existing;
                    }
                    $id = DB::table('insurance_prior_authorization_evidence')->insertGetId([...$values, 'command_key' => $data['command_key']]);
                    $this->audit->record('prior_authorization_evidence_linked', 'insurance_prior_authorization_evidence', $id, $actor->id);

                    return DB::table('insurance_prior_authorization_evidence')->find($id);
                }, 3);
            });
        }, 3);
    }

    public function listing(Patient $patient, InsurancePriorAuthorization $authorization, User $actor): array
    {
        $patient = $this->targetAuth($patient, $authorization, $actor);
        $this->ability($actor, 'evidence');
        $this->ability($actor, 'view');
        $rows = DB::table('insurance_prior_authorization_evidence')->where('tenant_id', $this->context->requireId())->where('patient_id', $patient->id)
            ->where(function ($q) use ($authorization) {
                $q->where('authorization_id', $authorization->id)->orWhereIn('decision_id', $authorization->decisions()->select('id'));
                if ($authorization->claim_id !== null) {
                    $q->orWhere('claim_id', $authorization->claim_id)->orWhereIn('submission_id', DB::table('insurance_claim_submissions')->select('id')->where('tenant_id', $this->context->requireId())->where('claim_id', $authorization->claim_id));
                }
            })->orderBy('id')->get();
        foreach ($rows as $row) {
            $version = \App\Models\DocumentVersion::query()->where('tenant_id', $this->context->requireId())->findOrFail($row->version_id);
            $document = PatientDocument::withTrashed()->findOrFail($version->document_id);
            Gate::forUser($actor)->authorize('view', $document);
            $this->protection->assertReadable($document);
            $this->audit->record('prior_authorization_evidence_version_accessed', 'document_versions', $version->id, $actor->id);
        }
        $this->audit->record('prior_authorization_evidence_accessed', 'insurance_prior_authorizations', $authorization->id, $actor->id);

        return $rows->map(fn ($r) => ['id' => $r->id, 'version_id' => $r->version_id, 'target_type' => $r->target_type, 'authorization_id' => $r->authorization_id, 'decision_id' => $r->decision_id, 'created_at' => $r->created_at])->all();
    }

    private function typedTarget(Patient $patient, InsurancePriorAuthorization $authorization, string $type, int $id): object
    {
        return match ($type) {
            'authorization' => $authorization->id === $id ? $authorization : abort(404),
            'decision' => InsurancePriorAuthorizationDecision::query()->where('authorization_id', $authorization->id)->findOrFail($id),
            'claim' => $this->claim($patient, $authorization, $id),
            'submission' => $this->submission($patient, $authorization, $id),
        };
    }

    private function claim(Patient $patient, InsurancePriorAuthorization $a, int $id): InsuranceClaim
    {
        abort_unless($a->claim_id === $id, 404);

        return InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($id);
    }

    private function submission(Patient $patient, InsurancePriorAuthorization $a, int $id): object
    {
        abort_unless($a->claim_id !== null, 404);

        return DB::table('insurance_claim_submissions')->where('tenant_id', $this->context->requireId())->where('claim_id', $a->claim_id)->find($id) ?? abort(404);
    }

    private function targetAuth(Patient $patient, InsurancePriorAuthorization $authorization, User $actor): Patient
    {
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('patients.view') && $actor->hasPermission('insurance_prior_authorizations.view'), 403);
        $patient = Patient::query()->findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);
        InsurancePriorAuthorization::query()->where('patient_id',$patient->id)->findOrFail($authorization->id);

        return $patient;
    }

    private function ability(User $actor,string $action): void
    {
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('insurance_prior_authorizations.'.$action) && $actor->hasPermission('insurance_documents.view'),403);
    }
}
