<?php

namespace App\Services;

use App\Billing\Money;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimEvent;
use App\Models\InsuranceClaimLine;
use App\Models\InsuranceClaimSubmission;
use App\Models\InsuranceEstimate;
use App\Models\Patient;
use App\Models\PatientInsurancePolicyRevision;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsuranceClaimWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    public function listing(Patient $patient, User $actor, array $input)
    {
        $this->authorize($patient, $actor, 'view');
        $data = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', 'in:draft,ready,submitted,cancelled'],
        ])->validate();
        $query = InsuranceClaim::query()->where('patient_id', $patient->id)->with('lines')->orderByDesc('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->paginate($data['per_page'] ?? 15);
        $this->audit->record('claims_listed', 'patient', $patient->id, $actor->id);

        return $page;
    }

    public function create(Patient $patient, array $input, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'prepare');
        $this->only($input, ['association_id', 'policy_revision_id', 'estimate_id', 'corrects_claim_id']);
        $data = Validator::make($input, [
            'association_id' => ['required', 'integer', 'min:1'],
            'policy_revision_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'estimate_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'corrects_claim_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();
        $association = PatientInsurerAssociation::query()->where('patient_id', $patient->id)->findOrFail($data['association_id']);
        $this->validateSources($patient, $association->id, $data);

        return DB::transaction(function () use ($patient, $association, $data, $actor) {
            $claim = new InsuranceClaim;
            $claim->forceFill([
                'patient_id' => $patient->id, 'association_id' => $association->id,
                'policy_revision_id' => $data['policy_revision_id'] ?? null, 'estimate_id' => $data['estimate_id'] ?? null,
                'corrects_claim_id' => $data['corrects_claim_id'] ?? null, 'claim_identifier' => (string) Str::uuid(),
                'status' => 'draft', 'lock_version' => 1, 'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ])->save();
            $this->event($claim, null, 'draft', 'created', $actor);
            $this->audit->record('insurance_claim_created', 'insurance_claims', $claim->id, $actor->id);

            return $claim->load('lines');
        }, 3);
    }

    public function show(Patient $patient, InsuranceClaim $claim, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'view');
        $claim = $this->claimForPatient($patient, $claim->id);
        $this->audit->record('insurance_claim_viewed', 'insurance_claims', $claim->id, $actor->id);

        return $claim->load('lines');
    }

    public function update(Patient $patient, InsuranceClaim $claim, array $input, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'prepare');
        $this->only($input, ['expected_version', 'policy_revision_id', 'estimate_id']);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'policy_revision_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'estimate_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($patient, $claim, $data, $actor) {
            $current = $this->lockedClaim($patient, $claim->id);
            $this->expectVersion($current, $data['expected_version']);
            $this->assertEditable($current);
            $this->validateSources($patient, $current->association_id, $data);
            $this->advance($current, 'draft', 'draft_updated', $actor, null, array_intersect_key($data, array_flip(['policy_revision_id', 'estimate_id'])));
            $this->audit->record('insurance_claim_updated', 'insurance_claims', $current->id, $actor->id);

            return $current->load('lines');
        }, 3);
    }

    public function addLine(Patient $patient, InsuranceClaim $claim, array $input, User $actor): array
    {
        return DB::transaction(function () use ($patient, $claim, $input, $actor) {
            $current = $this->editableFromInput($patient, $claim, $input, $actor);
            $lineData = $this->lineData($input);
            $this->validateTreatmentSource($current, $lineData['treatment_plan_item_id'] ?? null);
            $line = new InsuranceClaimLine;
            $line->forceFill(['claim_id' => $current->id, ...$lineData, 'created_at' => now('UTC'), 'updated_at' => now('UTC')])->save();
            $line->refresh();
            $this->audit->record('insurance_claim_line_created', 'insurance_claims', $current->id, $actor->id);

            return ['claim' => $current->load('lines'), 'line' => $line];
        }, 3);
    }

    public function updateLine(Patient $patient, InsuranceClaim $claim, InsuranceClaimLine $line, array $input, User $actor): array
    {
        return DB::transaction(function () use ($patient, $claim, $line, $input, $actor) {
            $current = $this->editableFromInput($patient, $claim, $input, $actor);
            $target = $current->lines()->lockForUpdate()->findOrFail($line->id);
            $lineData = $this->lineData($input);
            $this->validateTreatmentSource($current, $lineData['treatment_plan_item_id'] ?? null);
            $target->forceFill([...$lineData, 'updated_at' => now('UTC')])->save();
            $target->refresh();
            $this->audit->record('insurance_claim_line_updated', 'insurance_claims', $current->id, $actor->id);

            return ['claim' => $current->load('lines'), 'line' => $target];
        }, 3);
    }

    public function removeLine(Patient $patient, InsuranceClaim $claim, InsuranceClaimLine $line, array $input, User $actor): InsuranceClaim
    {
        DB::transaction(function () use ($patient, $claim, $line, $input, $actor) {
            $current = $this->editableFromInput($patient, $claim, $input, $actor);
            $target = $current->lines()->lockForUpdate()->findOrFail($line->id);
            $target->delete();
            $this->audit->record('insurance_claim_line_removed', 'insurance_claims', $current->id, $actor->id);

            return $current->load('lines');
        }, 3);
    }

    public function validateReadiness(Patient $patient, InsuranceClaim $claim, array $input, User $actor): array
    {
        $patient = $this->authorize($patient, $actor, 'prepare');
        $this->only($input, ['expected_version']);
        $expectedVersion = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];

        return DB::transaction(function () use ($patient, $claim, $expectedVersion, $actor) {
            $current = $this->lockedClaim($patient, $claim->id);
            $this->expectVersion($current, $expectedVersion);
            abort_unless(in_array($current->status, ['draft', 'ready'], true), 409, 'Only draft claims can be validated.');
            $reasons = $this->readinessReasons($current);
            $this->audit->record('insurance_claim_readiness_checked', 'insurance_claims', $current->id, $actor->id);
            if ($reasons === [] && $current->status === 'draft') {
                $this->advance($current, 'ready', 'validated', $actor);
                $this->audit->record('insurance_claim_readiness_validated', 'insurance_claims', $current->id, $actor->id);
            }

            return ['claim' => $current->load('lines'), 'ready' => $reasons === [], 'reason_codes' => $reasons];
        }, 3);
    }

    public function cancel(Patient $patient, InsuranceClaim $claim, array $input, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'prepare');
        $this->only($input, ['expected_version', 'reason']);
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:500']])->validate();

        return DB::transaction(function () use ($patient, $claim, $data, $actor) {
            $current = $this->lockedClaim($patient, $claim->id);
            $this->expectVersion($current, $data['expected_version']);
            abort_unless(in_array($current->status, ['draft', 'ready'], true), 409, 'Only draft or ready claims can be cancelled.');
            $this->advance($current, 'cancelled', 'cancelled', $actor, $data['reason']);
            $this->audit->record('insurance_claim_cancelled', 'insurance_claims', $current->id, $actor->id);

            return $current->load('lines');
        }, 3);
    }

    public function submit(Patient $patient, InsuranceClaim $claim, array $input, string $idempotencyKey, User $actor): array
    {
        $patient = $this->authorize($patient, $actor, 'submit');
        $this->only($input, ['expected_version', 'channel', 'channel_metadata']);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'], 'channel' => ['required', 'in:portal,email,paper,other'],
            'channel_metadata' => ['sometimes', 'array'], 'channel_metadata.method_note' => ['sometimes', 'string', 'max:500'],
        ])->validate();
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A nonempty idempotency key of at most 120 characters is required.']]);
        }
        $channelMetadata = $data['channel_metadata'] ?? new \stdClass;
        $requestHash = hash('sha256', json_encode(['channel' => $data['channel'], 'channel_metadata' => $channelMetadata], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($patient, $claim, $data, $idempotencyKey, $actor, $channelMetadata, $requestHash) {
            $current = $this->lockedClaim($patient, $claim->id);
            $existing = InsuranceClaimSubmission::query()->where('claim_id', $current->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'Idempotency key was already used with different submission data.');
                $this->audit->record('insurance_claim_submission_history_viewed', 'insurance_claims', $current->id, $actor->id);

                return ['claim' => $current->load('lines'), 'submission' => $existing, 'replayed' => true];
            }
            $this->expectVersion($current, $data['expected_version']);
            abort_unless($current->status === 'ready', 409, 'Only a ready claim can be submitted.');
            $reasons = $this->readinessReasons($current);
            if ($reasons !== []) {
                throw ValidationException::withMessages(['readiness' => $reasons]);
            }

            $snapshot = [
                'claim_identifier' => $current->claim_identifier, 'patient_id' => $current->patient_id,
                'association_id' => $current->association_id, 'policy_revision_id' => $current->policy_revision_id,
                'estimate_id' => $current->estimate_id, 'prepared_claim_version' => $current->lock_version,
                'lines' => $current->lines->map(fn (InsuranceClaimLine $line) => [
                    'treatment_plan_item_id' => $line->treatment_plan_item_id,
                    'treatment_description' => $line->treatment_description, 'procedure_code' => $line->procedure_code,
                    'code_system' => $line->code_system, 'service_date' => $line->service_date->format('Y-m-d'),
                    'quantity' => $line->quantity, 'unit_amount' => (string) $line->unit_amount,
                    'gross_amount' => (string) $line->gross_amount, 'currency' => $line->currency,
                ])->values()->all(),
            ];
            $sequence = ((int) InsuranceClaimSubmission::query()->where('claim_id', $current->id)->max('sequence')) + 1;
            $submission = new InsuranceClaimSubmission;
            $submission->forceFill([
                'claim_id' => $current->id, 'sequence' => $sequence, 'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash, 'channel' => $data['channel'], 'channel_metadata' => $channelMetadata,
                'claim_version' => $current->lock_version, 'snapshot' => $snapshot, 'submitted_by_user_id' => $actor->id,
                'submitted_at' => now('UTC'),
            ])->save();
            $this->advance($current, 'submitted', 'submitted', $actor);
            $this->audit->record('insurance_claim_submission_recorded', 'insurance_claims', $current->id, $actor->id);

            return ['claim' => $current->load('lines'), 'submission' => $submission, 'replayed' => false];
        }, 3);
    }

    public function submissions(Patient $patient, InsuranceClaim $claim, User $actor)
    {
        $current = $this->readClaim($patient, $claim, $actor);
        $this->audit->record('insurance_claim_submission_history_viewed', 'insurance_claims', $current->id, $actor->id);

        return $current->submissions;
    }

    public function events(Patient $patient, InsuranceClaim $claim, User $actor)
    {
        $current = $this->readClaim($patient, $claim, $actor);
        $this->audit->record('insurance_claim_lifecycle_history_viewed', 'insurance_claims', $current->id, $actor->id);

        return $current->events;
    }

    private function editableFromInput(Patient $patient, InsuranceClaim $claim, array $input, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'prepare');
        $version = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
        $current = $this->lockedClaim($patient, $claim->id);
        $this->expectVersion($current, $version);
        $this->assertEditable($current);
        $this->advance($current, 'draft', 'draft_updated', $actor);

        return $current;
    }

    private function lineData(array $input): array
    {
        $this->only($input, ['expected_version', 'treatment_plan_item_id', 'treatment_description', 'procedure_code', 'code_system', 'service_date', 'quantity', 'unit_amount', 'currency']);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'treatment_plan_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'treatment_description' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'procedure_code' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/\S/u'],
            'code_system' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/\S/u'],
            'service_date' => ['required', 'date_format:Y-m-d'], 'quantity' => ['required', 'integer', 'between:1,10000'],
            'unit_amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        if (isset($data['procedure_code']) !== isset($data['code_system'])) {
            throw ValidationException::withMessages(['code_system' => ['A code system must accompany an explicitly supplied procedure code.']]);
        }
        try {
            $unitMinor = Money::minor($data['unit_amount']);
            if ($unitMinor * $data['quantity'] > Money::MAX_MINOR) {
                throw new \InvalidArgumentException;
            }
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['unit_amount' => ['Use exact decimal currency with at most two fraction digits.']]);
        }
        unset($data['expected_version']);
        $data['treatment_description'] = trim($data['treatment_description']);

        return $data;
    }

    private function readinessReasons(InsuranceClaim $claim): array
    {
        $claim->loadMissing('lines');
        $reasons = [];
        if ($claim->lines->isEmpty()) {
            $reasons[] = 'CLAIM_LINES_REQUIRED';
        }
        if ($claim->lines->contains(fn (InsuranceClaimLine $line) => Money::minor((string) $line->gross_amount) <= 0)) {
            $reasons[] = 'POSITIVE_LINE_AMOUNT_REQUIRED';
        }
        if ($claim->lines->pluck('currency')->unique()->count() > 1) {
            $reasons[] = 'CLAIM_LINES_CURRENCY_MISMATCH';
        }
        if ($claim->policy_revision_id !== null && ! PatientInsurancePolicyRevision::query()->where('association_id', $claim->association_id)->whereKey($claim->policy_revision_id)->exists()) {
            $reasons[] = 'POLICY_REVISION_UNAVAILABLE';
        }
        if ($claim->estimate_id !== null) {
            $estimate = InsuranceEstimate::query()->where('patient_id', $claim->patient_id)->where(function ($q) use ($claim) {
                $q->whereNull('association_id')->orWhere('association_id', $claim->association_id);
            })->find($claim->estimate_id);
            if ($estimate === null) {
                $reasons[] = 'ESTIMATE_REFERENCE_MISMATCH';
            } elseif ($claim->lines->pluck('currency')->unique()->count() === 1
                && $estimate->currency !== $claim->lines->first()->currency) {
                $reasons[] = 'ESTIMATE_CURRENCY_MISMATCH';
            }
        }

        return $reasons;
    }

    private function validateSources(Patient $patient, int $associationId, array $data): void
    {
        if (isset($data['policy_revision_id']) && $data['policy_revision_id'] !== null) {
            PatientInsurancePolicyRevision::query()->where('association_id', $associationId)->findOrFail($data['policy_revision_id']);
        }
        if (isset($data['estimate_id']) && $data['estimate_id'] !== null) {
            InsuranceEstimate::query()->where('patient_id', $patient->id)->where(function ($q) use ($associationId) {
                $q->whereNull('association_id')->orWhere('association_id', $associationId);
            })->findOrFail($data['estimate_id']);
        }
        if (isset($data['corrects_claim_id']) && $data['corrects_claim_id'] !== null) {
            $prior = InsuranceClaim::query()->where('patient_id', $patient->id)->where('association_id', $associationId)->findOrFail($data['corrects_claim_id']);
            abort_unless($prior->status === 'submitted', 422, 'Corrections must link to a submitted claim.');
        }
    }

    private function validateTreatmentSource(InsuranceClaim $claim, ?int $itemId): void
    {
        if ($itemId !== null && ! DB::table('treatment_plan_items as i')
            ->join('treatment_plans as p', fn ($join) => $join->on('p.id', '=', 'i.treatment_plan_id')->on('p.tenant_id', '=', 'i.tenant_id'))
            ->where('i.tenant_id', $this->context->requireId())->where('i.id', $itemId)->where('p.patient_id', $claim->patient_id)->exists()) {
            abort(404);
        }
    }

    private function authorize(Patient $patient, User $actor, string $action): Patient
    {
        $tenantId = $this->context->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('patients.view')
            && $actor->hasPermission('insurance_claims.'.$action), 403);
        $patient = Patient::query()->where('tenant_id', $tenantId)->findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);

        return $patient;
    }

    private function only(array $input, array $allowed): void
    {
        $unsupported = array_diff(array_keys($input), $allowed);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([reset($unsupported) => ['Unsupported insurance claim field.']]);
        }
    }

    private function readClaim(Patient $patient, InsuranceClaim $claim, User $actor): InsuranceClaim
    {
        $patient = $this->authorize($patient, $actor, 'view');
        $current = $this->claimForPatient($patient, $claim->id)->load('lines');

        return $current;
    }

    private function claimForPatient(Patient $patient, int $claimId): InsuranceClaim
    {
        return InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($claimId);
    }

    private function lockedClaim(Patient $patient, int $claimId): InsuranceClaim
    {
        return InsuranceClaim::query()->where('patient_id', $patient->id)->lockForUpdate()->findOrFail($claimId);
    }

    private function expectVersion(InsuranceClaim $claim, int $version): void
    {
        abort_unless($claim->lock_version === $version, 409, 'Claim version is stale; reload before retrying.');
    }

    private function assertEditable(InsuranceClaim $claim): void
    {
        abort_unless(in_array($claim->status, ['draft', 'ready'], true), 409, 'Submitted or cancelled claims cannot be edited.');
    }

    private function advance(InsuranceClaim $claim, string $status, string $eventType, User $actor, ?string $reason = null, array $changes = []): void
    {
        $from = $claim->status;
        foreach ($changes as $field => $value) {
            $claim->{$field} = $value;
        }
        $claim->status = $status;
        $claim->lock_version++;
        $claim->updated_by_user_id = $actor->id;
        $claim->updated_at = now('UTC');
        $claim->save();
        $this->event($claim, $from, $status, $eventType, $actor, $reason);
    }

    private function event(InsuranceClaim $claim, ?string $from, string $to, string $type, User $actor, ?string $reason = null): void
    {
        $event = new InsuranceClaimEvent;
        $event->forceFill(['claim_id' => $claim->id, 'version' => $claim->lock_version, 'from_status' => $from,
            'to_status' => $to, 'event_type' => $type, 'reason' => $reason, 'actor_id' => $actor->id,
            'occurred_at' => now('UTC')])->save();
        DB::statement('SET CONSTRAINTS insurance_claim_require_history_trigger IMMEDIATE');
        DB::statement('SET CONSTRAINTS insurance_claim_require_history_trigger DEFERRED');
    }
}
