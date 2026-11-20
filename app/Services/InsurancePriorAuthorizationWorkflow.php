<?php

namespace App\Services;

use App\Models\InsuranceClaim;
use App\Models\InsuranceEstimate;
use App\Models\InsurancePriorAuthorization;
use App\Models\InsurancePriorAuthorizationDecision;
use App\Models\InsurancePriorAuthorizationEvent;
use App\Models\Patient;
use App\Models\PatientInsurancePolicyRevision;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsurancePriorAuthorizationWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    public function listing(Patient $patient, User $actor, array $input)
    {
        $patient = $this->authorize($patient, $actor, 'view');
        $data = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'status' => ['sometimes', 'in:'.implode(',', InsurancePriorAuthorization::STATUSES)]])->validate();
        $page = InsurancePriorAuthorization::query()->where('patient_id', $patient->id)->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $this->audit->record('prior_authorizations_listed', 'patient', $patient->id, $actor->id);

        return $page;
    }

    public function create(Patient $patient, array $input, string $idempotencyKey, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');
        $this->only($input, ['association_id', 'policy_revision_id', 'claim_id', 'treatment_plan_item_id', 'estimate_id', 'request_date', 'treatment_description', 'procedure_details', 'requested_amount', 'currency']);
        $data = Validator::make($input, [
            'association_id' => ['required', 'integer', 'min:1'], 'policy_revision_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'claim_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'treatment_plan_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'estimate_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'request_date' => ['required', 'date_format:Y-m-d'], 'treatment_description' => ['required', 'string', 'max:4000', 'regex:/\S/u'], 'procedure_details' => ['sometimes', 'array'],
            'requested_amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }
        $association = PatientInsurerAssociation::query()->where('patient_id', $patient->id)->findOrFail($data['association_id']);
        $this->validateSources($patient, $association->id, $data);
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($patient, $association, $data, $actor, $idempotencyKey, $requestHash) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$this->context->requireId().':prior-auth:'.$idempotencyKey]);
            $existing = InsurancePriorAuthorization::query()->where('command_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless($existing->patient_id === $patient->id && hash_equals($existing->request_hash, $requestHash), 409, 'Idempotency key was already used with different request data.');
                $this->audit->record('prior_authorization_creation_replayed', 'insurance_prior_authorizations', $existing->id, $actor->id);

                return $existing->load('events');
            }
            $row = new InsurancePriorAuthorization;
            $row->forceFill(['patient_id' => $patient->id, 'association_id' => $association->id, 'policy_revision_id' => $data['policy_revision_id'] ?? null,
                'claim_id' => $data['claim_id'] ?? null, 'treatment_plan_item_id' => $data['treatment_plan_item_id'] ?? null, 'estimate_id' => $data['estimate_id'] ?? null,
                'command_key' => $idempotencyKey, 'request_hash' => $requestHash,
                'request_identifier' => (string) Str::uuid(), 'request_date' => $data['request_date'], 'treatment_description' => trim($data['treatment_description']),
                'procedure_details' => $data['procedure_details'] ?? new \stdClass, 'requested_amount' => $data['requested_amount'], 'currency' => $data['currency'],
                'status' => 'draft', 'lock_version' => 1, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')])->save();
            $this->event($row, null, 'draft', 'created', $actor);
            $this->audit->record('prior_authorization_created', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load('events');
        }, 3);
    }

    public function show(Patient $patient, InsurancePriorAuthorization $authorization, User $actor): InsurancePriorAuthorization
    {
        $this->authorize($patient, $actor, 'view');
        $row = $this->forPatient($patient, $authorization->id)->load(['events', 'decisions']);
        $this->audit->record('prior_authorization_viewed', 'insurance_prior_authorizations', $row->id, $actor->id);

        return $row;
    }

    public function updateDraft(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');
        $allowed = ['expected_version', 'policy_revision_id', 'claim_id', 'treatment_plan_item_id', 'estimate_id', 'request_date', 'treatment_description', 'procedure_details', 'requested_amount', 'currency'];
        $this->only($input, $allowed);
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'policy_revision_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'claim_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'treatment_plan_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'estimate_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'request_date' => ['sometimes', 'date_format:Y-m-d'],
            'treatment_description' => ['sometimes', 'string', 'max:4000', 'regex:/\S/u'], 'procedure_details' => ['sometimes', 'array'], 'requested_amount' => ['sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/']])->validate();

        return DB::transaction(function () use ($patient, $authorization, $data, $actor) {
            $row = $this->locked($patient, $authorization->id);
            abort_unless($row->status === 'draft', 409, 'Only draft requests can be changed.');
            $this->expect($row, $data['expected_version']);
            $next = array_diff_key($data, ['expected_version' => true]);
            if (isset($next['treatment_description'])) {
                $next['treatment_description'] = trim($next['treatment_description']);
            }
            $this->validateSources($patient, $row->association_id, $next);
            $row->forceFill([...$next, 'lock_version' => $row->lock_version + 1, 'updated_by_user_id' => $actor->id, 'updated_at' => now('UTC')])->save();
            $this->event($row, 'draft', 'draft', 'draft_updated', $actor);
            $this->audit->record('prior_authorization_updated', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load('events');
        }, 3);
    }

    public function recordRequest(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'communication_channel' => ['required', 'in:phone,portal,email,fax,paper,other'], 'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($patient, $authorization, $data, $actor) {
            $row = $this->locked($patient, $authorization->id);
            $this->expect($row, $data['expected_version']);
            abort_unless($row->status === 'draft', 409);
            $this->advance($row, 'requested', 'request_recorded', $actor, $data['source_provenance'], $data['communication_channel']);
            // The event records clinic-entered channel/provenance only: it is not insurer receipt.
            $this->audit->record('prior_authorization_request_recorded', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load('events');
        }, 3);
    }

    public function decision(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'decide');
        $this->only($input, ['expected_version', 'decision_type', 'insurer_reference', 'authorized_amount', 'currency', 'authorized_scope', 'valid_from', 'valid_through', 'communication_channel', 'decision_at', 'source_provenance', 'corrects_decision_id', 'correction_reason']);
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'decision_type' => ['required', 'in:acknowledged,approved,partially_approved,denied,additional_information_requested'],
            'insurer_reference' => ['sometimes', 'nullable', 'string', 'max:200'], 'authorized_amount' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'authorized_scope' => ['sometimes', 'nullable', 'string', 'max:4000'], 'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'valid_through' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'communication_channel' => ['required', 'in:phone,portal,email,fax,paper,other'], 'decision_at' => ['required', 'date'], 'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'corrects_decision_id' => ['sometimes', 'integer', 'min:1'], 'correction_reason' => ['required_with:corrects_decision_id', 'string', 'max:2000', 'regex:/\S/u']])->validate();
        if (isset($data['authorized_amount']) !== isset($data['currency'])) {
            throw ValidationException::withMessages(['currency' => ['Provide authorized amount and currency together; leave both unknown when absent.']]);
        }
        if (isset($data['valid_from'],$data['valid_through']) && $data['valid_through'] < $data['valid_from']) {
            throw ValidationException::withMessages(['valid_through' => ['Must not precede valid_from.']]);
        }
        $target = match ($data['decision_type']) {
            'acknowledged','additional_information_requested' => 'pending',default => $data['decision_type']
        };
        $corrects = $data['corrects_decision_id'] ?? null;

        return DB::transaction(function () use ($patient, $authorization, $data, $actor, $target, $corrects) {
            $row = $this->locked($patient, $authorization->id);
            $this->expect($row, $data['expected_version']);
            if (isset($data['currency']) && $data['currency'] !== $row->currency) {
                throw ValidationException::withMessages(['currency' => ['Authorized currency must match the requested currency.']]);
            }
            if ($corrects !== null) {
                abort_unless(in_array($row->status, ['approved', 'partially_approved', 'denied'], true) && in_array($target, ['approved', 'partially_approved', 'denied'], true), 409, 'Only a terminal insurer decision can be corrected with a new terminal decision.');
                $prior = $row->decisions()->orderByDesc('id')->first();
                abort_unless($prior && $prior->id === $corrects, 409, 'Corrections must reference the latest decision.');
                $eventType = 'correction_recorded';
                $reason = $data['correction_reason'].' | '.$data['source_provenance'];
            } else {
                abort_unless(in_array($row->status, ['requested', 'pending'], true), 409, 'Insurer decisions require a clinic-recorded request.');
                $type = $data['decision_type'];
                $eventType = match ($type) {
                    'acknowledged' => 'insurer_acknowledged','additional_information_requested' => 'additional_information_requested',default => 'decision_recorded'
                };
                $reason = $data['source_provenance'];
            }
            $event = $this->advance($row, $target, $eventType, $actor, $reason, $data['communication_channel']);
            $decision = new InsurancePriorAuthorizationDecision;
            $decision->forceFill(['authorization_id' => $row->id, 'event_id' => $event->id, 'corrects_decision_id' => $corrects, 'decision_type' => $data['decision_type'], 'insurer_reference_ciphertext' => isset($data['insurer_reference']) && $data['insurer_reference'] !== null ? Crypt::encryptString($data['insurer_reference']) : null,
                'authorized_amount' => $data['authorized_amount'] ?? null, 'currency' => $data['currency'] ?? null, 'authorized_scope' => $data['authorized_scope'] ?? null, 'valid_from' => $data['valid_from'] ?? null, 'valid_through' => $data['valid_through'] ?? null,
                'communication_channel' => $data['communication_channel'], 'decision_at' => $data['decision_at'], 'recorded_by_user_id' => $actor->id, 'source_provenance' => $data['source_provenance']])->save();
            $this->audit->record('prior_authorization_decision_recorded', 'insurance_prior_authorization_decisions', $decision->id, $actor->id);

            return $row->load(['events', 'decisions']);
        }, 3);
    }

    public function linkClaim(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');
        $claimId = Validator::make($input, ['claim_id' => ['required', 'integer', 'min:1']])->validate()['claim_id'];

        return DB::transaction(function () use ($patient, $authorization, $claimId, $actor) {
            $row = $this->locked($patient, $authorization->id);
            $claim = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claimId);
            abort_unless($claim->association_id === $row->association_id, 422, 'Claim must use the same insurance association.');
            abort_unless($row->claim_id === null || $row->claim_id === $claimId, 409, 'Authorization is already linked to another claim.');
            if ($row->claim_id === $claim->id) {
                $this->audit->record('prior_authorization_claim_link_replayed', 'insurance_prior_authorizations', $row->id, $actor->id);

                return $row->load('events');
            }
            $row->claim_id = $claim->id;
            $row->lock_version++;
            $row->updated_by_user_id = $actor->id;
            $row->updated_at = now('UTC');
            $row->save();
            $this->event($row, $row->status, $row->status, 'claim_linked', $actor);
            $this->audit->record('prior_authorization_claim_linked', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load('events');
        }, 3);
    }

    public function expire(Patient $patient, InsurancePriorAuthorization $authorization, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');

        return DB::transaction(function () use ($patient, $authorization, $actor) {
            $row = $this->locked($patient, $authorization->id);
            abort_unless(in_array($row->status, ['approved', 'partially_approved'], true), 409, 'Only an approved authorization can expire.');
            $last = $row->decisions()->whereIn('decision_type', ['approved', 'partially_approved'])->orderByDesc('id')->first();
            abort_unless($last?->valid_through !== null && $last->valid_through->lt(today()), 409, 'A past explicit valid_through date is required to expire this authorization.');
            $event = $this->advance($row, 'expired', 'expired', $actor, 'Expired after explicit insurer validity date.');
            $this->audit->record('prior_authorization_expired', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load(['events', 'decisions']);
        }, 3);
    }

    public function cancel(Patient $patient, InsurancePriorAuthorization $authorization, array $input, User $actor): InsurancePriorAuthorization
    {
        $patient = $this->authorize($patient, $actor, 'manage');
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($patient, $authorization, $data, $actor) {
            $row = $this->locked($patient, $authorization->id);
            $this->expect($row, $data['expected_version']);
            abort_unless(in_array($row->status, ['draft', 'requested', 'pending'], true), 409, 'Only nonterminal requests can be cancelled.');
            $this->advance($row, 'cancelled', 'cancelled', $actor, $data['reason']);
            $this->audit->record('prior_authorization_cancelled', 'insurance_prior_authorizations', $row->id, $actor->id);

            return $row->load('events');
        }, 3);
    }

    public function history(Patient $patient, InsurancePriorAuthorization $authorization, User $actor)
    {
        $row = $this->show($patient, $authorization, $actor);
        $this->audit->record('prior_authorization_history_viewed', 'insurance_prior_authorizations', $row->id, $actor->id);

        return $row->events;
    }

    private function validateSources(Patient $patient, int $associationId, array $data): void
    {
        if (isset($data['policy_revision_id']) && $data['policy_revision_id'] !== null) {
            PatientInsurancePolicyRevision::query()->where('association_id', $associationId)->findOrFail($data['policy_revision_id']);
        }
        if (isset($data['estimate_id']) && $data['estimate_id'] !== null) {
            InsuranceEstimate::query()->where('patient_id', $patient->id)->where(fn ($q) => $q->whereNull('association_id')->orWhere('association_id', $associationId))->findOrFail($data['estimate_id']);
        }
        if (isset($data['claim_id']) && $data['claim_id'] !== null) {
            $claim = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($data['claim_id']);
            abort_unless($claim->association_id === $associationId, 422, 'Claim must use the same insurance association.');
        }
        if (isset($data['treatment_plan_item_id']) && $data['treatment_plan_item_id'] !== null && ! DB::table('treatment_plan_items as i')->join('treatment_plans as p', fn ($j) => $j->on('p.id', '=', 'i.treatment_plan_id')->on('p.tenant_id', '=', 'i.tenant_id'))->where('i.tenant_id', $this->context->requireId())->where('i.id', $data['treatment_plan_item_id'])->where('p.patient_id', $patient->id)->exists()) {
            abort(404);
        }
    }

    private function authorize(Patient $patient, User $actor, string $action): Patient
    {
        $tenant = $this->context->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('patients.view') && $actor->hasPermission('insurance_prior_authorizations.'.$action), 403);
        $patient = Patient::query()->where('tenant_id', $tenant)->findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);

        return $patient;
    }

    private function only(array $input, array $allowed): void
    {
        $bad = array_diff(array_keys($input), $allowed);
        if ($bad) {
            throw ValidationException::withMessages([reset($bad) => ['Unsupported authorization field.']]);
        }
    }

    private function forPatient(Patient $patient, int $id): InsurancePriorAuthorization
    {
        return InsurancePriorAuthorization::query()->where('patient_id', $patient->id)->findOrFail($id);
    }

    private function locked(Patient $patient, int $id): InsurancePriorAuthorization
    {
        return InsurancePriorAuthorization::query()->where('patient_id', $patient->id)->lockForUpdate()->findOrFail($id);
    }

    private function expect(InsurancePriorAuthorization $row, int $version): void
    {
        abort_unless($row->lock_version === $version, 409, 'Authorization version is stale.');
    }

    private function advance(InsurancePriorAuthorization $row, string $status, string $eventType, User $actor, ?string $reason = null, ?string $channel = null): InsurancePriorAuthorizationEvent
    {
        $from = $row->status;
        $row->status = $status;
        $row->lock_version++;
        $row->updated_by_user_id = $actor->id;
        $row->updated_at = now('UTC');
        $row->save();

        return $this->event($row,$from,$status,$eventType,$actor,$reason,$channel);
    }

    private function event(InsurancePriorAuthorization $row,?string $from,string $to,string $type,User $actor,?string $reason = null,?string $channel = null): InsurancePriorAuthorizationEvent
    {
        $event = new InsurancePriorAuthorizationEvent;
        $event->forceFill(['authorization_id' => $row->id, 'version' => $row->lock_version, 'from_status' => $from, 'to_status' => $to, 'event_type' => $type, 'reason' => $reason, 'communication_channel' => $channel, 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();

        return $event;
    }
}
