<?php

namespace App\Services;

use App\Insurance\InsuranceClaimAccessPolicy;
use App\Insurance\InsuranceClaimLifecycle;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimAppeal;
use App\Models\InsuranceClaimAppealEvent;
use App\Models\InsuranceClaimAppealResponse;
use App\Models\InsuranceClaimAppealSubmission;
use App\Models\InsuranceClaimEvent;
use App\Models\InsuranceClaimResponse;
use App\Models\InsuranceClaimSubmission;
use App\Models\Patient;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InsuranceClaimResponseWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    public function record(Patient $patient, InsuranceClaim $claim, array $input, User $actor): InsuranceClaimResponse
    {
        $patient = $this->authorize($patient, $actor, 'responses.record');
        $allowed = ['submission_id', 'response_type', 'insurer_response_at', 'communication_channel', 'insurer_reference', 'source_provenance', 'notes', 'requested_information', 'response_deadline', 'evidence_id', 'approved_amount', 'currency'];
        $this->only($input, $allowed);
        $data = Validator::make($input, [
            'submission_id' => ['required', 'integer', 'min:1'], 'response_type' => ['required', 'in:acknowledged,under_review,additional_information_requested,rejected,approved,partially_approved,denied'],
            'insurer_response_at' => ['required', 'date'], 'communication_channel' => ['required', 'in:phone,portal,email,fax,paper,other'],
            'insurer_reference' => ['sometimes', 'nullable', 'string', 'max:200'], 'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'requested_information' => ['required_if:response_type,additional_information_requested', 'string', 'max:4000', 'regex:/\S/u'], 'response_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'evidence_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'approved_amount' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        if (isset($data['approved_amount']) !== isset($data['currency'])) {
            throw ValidationException::withMessages(['currency' => ['Provide an informational amount and currency together.']]);
        }
        if (($data['response_type'] !== 'additional_information_requested') && (($data['requested_information'] ?? null) !== null || ($data['response_deadline'] ?? null) !== null)) {
            throw ValidationException::withMessages(['requested_information' => ['Request details and deadlines apply only to additional information requests.']]);
        }
        if (! InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount($data['response_type'], isset($data['approved_amount']))) {
            throw ValidationException::withMessages(['approved_amount' => ['Informational approved amounts apply only to approval decisions.']]);
        }

        return DB::transaction(function () use ($patient, $claim, $data, $actor) {
            $row = InsuranceClaim::query()->where('patient_id', $patient->id)->lockForUpdate()->findOrFail($claim->id);
            $submission = InsuranceClaimSubmission::query()->where('claim_id', $row->id)->findOrFail($data['submission_id']);
            $next = $data['response_type'];
            abort_unless(InsuranceClaimLifecycle::canRecordResponse($row->status, $next), 409, 'Response is not a valid transition from the current claim status.');
            $evidenceId = $data['evidence_id'] ?? null;
            if ($evidenceId !== null) {
                abort_unless(DB::table('insurance_prior_authorization_evidence')->where('tenant_id', $this->context->requireId())->where('patient_id', $patient->id)
                    ->where('target_type', 'submission')->where('submission_id', $submission->id)->where('id', $evidenceId)->exists(), 404);
            }
            $response = new InsuranceClaimResponse;
            $response->forceFill([
                'patient_id' => $patient->id, 'claim_id' => $row->id, 'submission_id' => $submission->id, 'claim_version' => $row->lock_version + 1, 'response_type' => $next,
                'insurer_response_at' => $data['insurer_response_at'], 'recorded_at' => now('UTC'), 'recorded_by_user_id' => $actor->id,
                'communication_channel' => $data['communication_channel'], 'insurer_reference' => $data['insurer_reference'] ?? null,
                'source_provenance' => trim($data['source_provenance']), 'notes' => $data['notes'] ?? null,
                'requested_information' => $data['requested_information'] ?? null, 'response_deadline' => $data['response_deadline'] ?? null,
                'evidence_id' => $evidenceId, 'approved_amount' => $data['approved_amount'] ?? null, 'currency' => $data['currency'] ?? null,
            ])->save();
            $previous = $row->status;
            $row->status = $next;
            $row->lock_version++;
            $row->updated_by_user_id = $actor->id;
            $row->updated_at = now('UTC');
            $row->save();
            $event = new InsuranceClaimEvent;
            $event->forceFill(['claim_id' => $row->id, 'version' => $row->lock_version, 'from_status' => $previous, 'to_status' => $next, 'event_type' => 'insurer_response_recorded', 'reason' => $next === 'additional_information_requested' ? $data['requested_information'] : null, 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();
            $this->audit->record('insurance_claim_response_recorded', 'insurance_claim_responses', $response->id, $actor->id);

            return $response;
        }, 3);
    }

    public function responses(Patient $patient, InsuranceClaim $claim, User $actor)
    {
        $this->authorize($patient, $actor, 'responses.view');
        $row = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
        $this->audit->record('insurance_claim_response_history_viewed', 'insurance_claims', $row->id, $actor->id);

        return $row->responses()->paginate(15);
    }

    public function createAppeal(Patient $patient, InsuranceClaim $claim, array $input, User $actor): InsuranceClaimAppeal
    {
        $patient = $this->authorize($patient, $actor, 'appeals.manage');
        $this->only($input, ['submission_id', 'decision_response_id', 'appeal_reason']);
        $data = Validator::make($input, ['submission_id' => ['required', 'integer', 'min:1'], 'decision_response_id' => ['required', 'integer', 'min:1'], 'appeal_reason' => ['required', 'string', 'max:8000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($patient, $claim, $data, $actor) {
            $row = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
            $decision = InsuranceClaimResponse::query()->where('claim_id', $row->id)->findOrFail($data['decision_response_id']);
            abort_unless($decision->submission_id === (int) $data['submission_id'] && InsuranceClaimLifecycle::canAppeal($decision->response_type), 422, 'Appeals may only reference an eligible adverse decision for the same immutable submission.');
            $appeal = new InsuranceClaimAppeal;
            $appeal->forceFill(['patient_id' => $patient->id, 'claim_id' => $row->id, 'submission_id' => $decision->submission_id, 'decision_response_id' => $decision->id, 'appeal_reason' => trim($data['appeal_reason']), 'status' => 'draft', 'created_by_user_id' => $actor->id, 'created_at' => now('UTC')])->save();
            $event = new InsuranceClaimAppealEvent;
            $event->forceFill(['appeal_id' => $appeal->id, 'from_status' => null, 'to_status' => 'draft', 'event_type' => 'created', 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();
            $this->audit->record('insurance_claim_appeal_created', 'insurance_claim_appeals', $appeal->id, $actor->id);

            return $appeal;
        }, 3);
    }

    public function submitAppeal(Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal, array $input, User $actor): InsuranceClaimAppeal
    {
        $patient = $this->authorize($patient, $actor, 'appeals.manage');
        $this->only($input, ['channel']);
        $data = Validator::make($input, ['channel' => ['required', 'in:portal,email,fax,paper,other']])->validate();

        return DB::transaction(function () use ($patient, $claim, $appeal, $data, $actor) {
            $row = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
            $current = InsuranceClaimAppeal::query()->where('claim_id', $row->id)->lockForUpdate()->findOrFail($appeal->id);
            abort_unless($current->status === 'draft', 409, 'Only draft appeals can be submitted.');
            $sequence = (int) InsuranceClaimAppealSubmission::query()->where('appeal_id', $current->id)->max('sequence') + 1;
            $submission = new InsuranceClaimAppealSubmission;
            $submission->forceFill(['appeal_id' => $current->id, 'sequence' => $sequence, 'channel' => $data['channel'], 'snapshot' => ['appeal_reason' => $current->appeal_reason, 'claim_id' => $row->id, 'submission_id' => $current->submission_id, 'decision_response_id' => $current->decision_response_id], 'submitted_by_user_id' => $actor->id, 'submitted_at' => now('UTC')])->save();
            $current->status = 'submitted';
            $current->save();
            $event = new InsuranceClaimAppealEvent;
            $event->forceFill(['appeal_id' => $current->id, 'from_status' => 'draft', 'to_status' => 'submitted', 'event_type' => 'submitted', 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();
            $this->audit->record('insurance_claim_appeal_submitted', 'insurance_claim_appeals', $current->id, $actor->id);

            return $current->load('events', 'submissions');
        }, 3);
    }

    public function recordAppealResponse(Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal, array $input, User $actor): InsuranceClaimAppealResponse
    {
        $patient = $this->authorize($patient, $actor, 'appeals.manage');
        $this->only($input, ['appeal_submission_id', 'response_type', 'insurer_response_at', 'communication_channel', 'source_provenance', 'insurer_reference', 'notes', 'approved_amount', 'currency']);
        $data = Validator::make($input, ['appeal_submission_id' => ['required', 'integer', 'min:1'], 'response_type' => ['required', 'in:acknowledged,under_review,approved,partially_approved,denied'],
            'insurer_response_at' => ['required', 'date'], 'communication_channel' => ['required', 'in:phone,portal,email,fax,paper,other'], 'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\\S/u'],
            'insurer_reference' => ['sometimes', 'nullable', 'string', 'max:200'], 'notes' => ['sometimes', 'nullable', 'string', 'max:4000'], 'approved_amount' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/'], 'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/']])->validate();
        if (isset($data['approved_amount']) !== isset($data['currency'])) {
            throw ValidationException::withMessages(['currency' => ['Provide an informational amount and currency together.']]);
        }
        if (! InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount($data['response_type'], isset($data['approved_amount']))) {
            throw ValidationException::withMessages(['approved_amount' => ['Informational approved amounts apply only to approval decisions.']]);
        }

        return DB::transaction(function () use ($patient, $claim, $appeal, $data, $actor) {
            $claimRow = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
            $current = InsuranceClaimAppeal::query()->where('claim_id', $claimRow->id)->lockForUpdate()->findOrFail($appeal->id);
            $submission = InsuranceClaimAppealSubmission::query()->where('appeal_id', $current->id)->findOrFail($data['appeal_submission_id']);
            $next = $data['response_type'];
            abort_unless(InsuranceClaimLifecycle::canRecordAppealResponse($current->status, $next), 409, 'Appeal response is not a valid transition.');
            $response = new InsuranceClaimAppealResponse;
            $response->forceFill(['appeal_id' => $current->id, 'appeal_submission_id' => $submission->id, 'response_type' => $next, 'insurer_response_at' => $data['insurer_response_at'], 'recorded_at' => now('UTC'), 'recorded_by_user_id' => $actor->id,
                'communication_channel' => $data['communication_channel'], 'source_provenance' => trim($data['source_provenance']), 'insurer_reference' => $data['insurer_reference'] ?? null, 'notes' => $data['notes'] ?? null, 'approved_amount' => $data['approved_amount'] ?? null, 'currency' => $data['currency'] ?? null])->save();
            $previous = $current->status;
            $current->status = $next;
            $current->save();
            $event = new InsuranceClaimAppealEvent;
            $event->forceFill(['appeal_id' => $current->id, 'from_status' => $previous, 'to_status' => $next, 'event_type' => 'insurer_response_recorded', 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();
            $this->audit->record('insurance_claim_appeal_response_recorded', 'insurance_claim_appeal_responses', $response->id, $actor->id);

            return $response;
        }, 3);
    }

    public function appealHistory(Patient $patient, InsuranceClaim $claim, User $actor)
    {
        $patient = $this->authorize($patient, $actor, 'appeals.view');
        $row = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
        $this->audit->record('insurance_claim_appeal_history_viewed', 'insurance_claims', $row->id, $actor->id);

        return InsuranceClaimAppeal::query()->where('claim_id', $row->id)->with(['events', 'submissions', 'responses'])->paginate(15);
    }

    public function withdrawAppeal(Patient $patient, InsuranceClaim $claim, InsuranceClaimAppeal $appeal, User $actor): InsuranceClaimAppeal
    {
        $patient = $this->authorize($patient, $actor, 'appeals.manage');

        return DB::transaction(function () use ($patient, $claim, $appeal, $actor) {
            $row = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claim->id);
            $current = InsuranceClaimAppeal::query()->where('claim_id', $row->id)->lockForUpdate()->findOrFail($appeal->id);
            abort_unless(InsuranceClaimLifecycle::canWithdrawAppeal($current->status), 409, 'Only a draft or submitted appeal can be withdrawn.');
            $previous = $current->status;
            $current->status = 'withdrawn';
            $current->save();
            $event = new InsuranceClaimAppealEvent;
            $event->forceFill(['appeal_id' => $current->id, 'from_status' => $previous, 'to_status' => 'withdrawn', 'event_type' => 'withdrawn', 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->save();
            $this->audit->record('insurance_claim_appeal_withdrawn', 'insurance_claim_appeals', $current->id, $actor->id);

            return $current->load('events', 'submissions', 'responses');
        }, 3);
    }

    private function authorize(Patient $patient, User $actor, string $permission): Patient
    {
        $tenantId = $this->context->requireId();
        abort_unless(InsuranceClaimAccessPolicy::allows($actor, $permission), 403);
        $patient = Patient::query()->where('tenant_id', $tenantId)->findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);

        return $patient;
    }

    private function only(array $input, array $allowed): void
    {
        $unsupported = array_diff(array_keys($input), $allowed);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([reset($unsupported) => ['Unsupported insurance claim response field.']]);
        }
    }
}
