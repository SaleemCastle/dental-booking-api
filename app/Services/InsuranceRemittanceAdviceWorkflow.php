<?php

namespace App\Services;

use App\Documents\DocumentProtection;
use App\Insurance\InsuranceRemittanceAccessPolicy;
use App\Insurance\InsuranceRemittanceRules;
use App\Models\DocumentVersion;
use App\Models\InsuranceClaim;
use App\Models\InsuranceRemittanceAdvice;
use App\Models\InsuranceRemittanceLine;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsuranceRemittanceAdviceWorkflow
{
    private const TOTAL_FIELDS = [
        'reported_billed_total' => 'billed',
        'reported_allowed_total' => 'allowed',
        'reported_paid_total' => 'paid',
        'reported_denied_total' => 'denied',
        'reported_adjusted_total' => 'adjusted',
    ];

    private const LINE_AMOUNT_FIELDS = [
        'billed_amount' => false,
        'allowed_amount' => false,
        'paid_amount' => false,
        'denied_amount' => false,
        'adjusted_amount' => true,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogService $audit,
        private readonly DocumentProtection $protection,
    ) {}

    public function listing(array $input, User $actor)
    {
        $this->authorize($actor, 'view');
        $data = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'insurer_id' => ['sometimes', 'integer', 'min:1'],
            'advice_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'advice_date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:advice_date_from'],
        ])->validate();

        $query = InsuranceRemittanceAdvice::query()->orderByDesc('advice_date')->orderByDesc('id')
            ->when(isset($data['insurer_id']), fn ($q) => $q->where('insurer_id', $data['insurer_id']))
            ->when(isset($data['advice_date_from']), fn ($q) => $q->whereDate('advice_date', '>=', $data['advice_date_from']))
            ->when(isset($data['advice_date_to']), fn ($q) => $q->whereDate('advice_date', '<=', $data['advice_date_to']));
        $page = $query->paginate($data['per_page'] ?? 15);
        $this->audit->record('insurance_remittance_advice_listed', 'insurance_remittance_advices', 'list', $actor->id);

        return $page;
    }

    public function show(int $adviceId, User $actor): InsuranceRemittanceAdvice
    {
        $this->authorize($actor, 'view');
        $advice = InsuranceRemittanceAdvice::query()->findOrFail($adviceId);
        $this->audit->record('insurance_remittance_advice_viewed', 'insurance_remittance_advices', $advice->id, $actor->id);

        return $advice;
    }

    public function lines(int $adviceId, array $input, User $actor)
    {
        $this->authorize($actor, 'view');
        $data = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ])->validate();
        $advice = InsuranceRemittanceAdvice::query()->findOrFail($adviceId);
        $page = $advice->lines()->paginate($data['per_page'] ?? 50);
        $this->audit->record('insurance_remittance_lines_viewed', 'insurance_remittance_advices', $advice->id, $actor->id);

        return $page;
    }

    public function record(array $input, string $commandKey, User $actor): array
    {
        $this->authorize($actor, 'record');
        $data = $this->validated($input, false);
        if (! Str::isUuid($commandKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }

        return $this->persist($data, $commandKey, $actor, null);
    }

    public function correct(int $adviceId, array $input, string $commandKey, User $actor): array
    {
        $this->authorize($actor, 'correct');
        $data = $this->validated($input, true);
        if (! Str::isUuid($commandKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }

        $parent = InsuranceRemittanceAdvice::query()->findOrFail($adviceId);
        $reason = trim($data['correction_reason']);
        unset($data['correction_reason']);
        $data['insurer_id'] = $parent->insurer_id;
        if ($data['currency'] !== $parent->currency) {
            throw ValidationException::withMessages(['currency' => ['A correction must retain the original currency.']]);
        }

        return $this->persist($data, $commandKey, $actor, ['id' => $parent->id, 'reason' => $reason]);
    }

    private function persist(array $data, string $commandKey, User $actor, ?array $correction): array
    {
        $tenantId = $this->context->requireId();
        $this->validateSources($data, $actor);
        $requestHash = hash('sha256', json_encode(['data' => $data, 'correction' => $correction], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $commandKey, $actor, $correction, $tenantId, $requestHash) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenantId.':insurer-remittance:command:'.$commandKey]);
            $existing = InsuranceRemittanceAdvice::query()->where('command_key', $commandKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'Idempotency key was already used with different remittance data.');
                $this->audit->record('insurance_remittance_advice_replayed', 'insurance_remittance_advices', $existing->id, $actor->id);

                return ['advice' => $existing->load('lines'), 'replayed' => true];
            }

            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                $tenantId.':insurer-remittance:'.($correction === null ? 'reference:'.$data['insurer_id'].':'.$data['external_reference'] : 'correction:'.$correction['id']),
            ]);
            if ($correction === null) {
                abort_if(InsuranceRemittanceAdvice::query()->where('insurer_id', $data['insurer_id'])
                    ->where('external_reference', $data['external_reference'])->whereNull('corrects_advice_id')->exists(), 409, 'A remittance with this insurer reference already exists.');
            } else {
                $parent = InsuranceRemittanceAdvice::query()->lockForUpdate()->findOrFail($correction['id']);
                abort_unless(InsuranceRemittanceRules::correctionAllowed(
                    (int) $parent->tenant_id === $tenantId,
                    (int) $parent->insurer_id === (int) $data['insurer_id'],
                    InsuranceRemittanceAdvice::query()->where('corrects_advice_id', $parent->id)->exists(),
                ), 409, 'This advice has already been corrected or does not match its correction.');
            }

            $advice = new InsuranceRemittanceAdvice;
            $values = [
                'insurer_id' => $data['insurer_id'],
                'external_reference' => trim($data['external_reference']),
                'advice_date' => $data['advice_date'],
                'recorded_at' => now('UTC'),
                'recorded_by_user_id' => $actor->id,
                'communication_channel' => $data['communication_channel'],
                'source_provenance' => trim($data['source_provenance']),
                'currency' => $data['currency'],
                'command_key' => $commandKey,
                'request_hash' => $requestHash,
                'corrects_advice_id' => $correction['id'] ?? null,
                'correction_reason' => $correction['reason'] ?? null,
            ];
            foreach (self::TOTAL_FIELDS as $column => $field) {
                $values[$column] = InsuranceRemittanceRules::amount($data['reported_totals'][$field] ?? null, $field === 'adjusted');
            }
            $advice->forceFill($values)->save();

            foreach ($data['lines'] as $index => $line) {
                $lineRow = new InsuranceRemittanceLine;
                $lineValues = [
                    'advice_id' => $advice->id,
                    'line_number' => $index + 1,
                    'external_line_reference' => isset($line['external_line_reference']) ? trim($line['external_line_reference']) : null,
                    'patient_id' => $line['patient_id'],
                    'association_id' => $line['association_id'],
                    'claim_id' => $line['claim_id'] ?? null,
                    'submission_id' => $line['submission_id'] ?? null,
                    'supporting_evidence_id' => $line['supporting_evidence_id'] ?? null,
                    'adjustment_reason' => isset($line['adjustment_reason']) ? trim($line['adjustment_reason']) : null,
                ];
                foreach (self::LINE_AMOUNT_FIELDS as $column => $allowNegative) {
                    $lineValues[$column] = InsuranceRemittanceRules::amount($line[$column] ?? null, $allowNegative);
                }
                $lineRow->forceFill($lineValues)->save();
            }

            $this->audit->record($correction === null ? 'insurance_remittance_advice_recorded' : 'insurance_remittance_advice_corrected', 'insurance_remittance_advices', $advice->id, $actor->id);

            return ['advice' => $advice->load('lines'), 'replayed' => false];
        }, 3);
    }

    private function validated(array $input, bool $correction): array
    {
        $totalRule = ['sometimes', 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'];
        $lineAmountRule = ['sometimes', 'nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'];
        $rules = [
            'external_reference' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'advice_date' => ['required', 'date_format:Y-m-d'],
            'communication_channel' => ['required', 'in:portal,email,fax,paper,phone,other'],
            'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'reported_totals' => ['sometimes', 'array:billed,allowed,paid,denied,adjusted'],
            'reported_totals.billed' => $totalRule,
            'reported_totals.allowed' => $totalRule,
            'reported_totals.paid' => $totalRule,
            'reported_totals.denied' => $totalRule,
            'reported_totals.adjusted' => ['sometimes', 'nullable', 'string', 'regex:/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*' => ['required', 'array:external_line_reference,patient_id,association_id,claim_id,submission_id,supporting_evidence_id,billed_amount,allowed_amount,paid_amount,denied_amount,adjusted_amount,adjustment_reason'],
            'lines.*.external_line_reference' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/\S/u'],
            'lines.*.patient_id' => ['required', 'integer', 'min:1'],
            'lines.*.association_id' => ['required', 'integer', 'min:1'],
            'lines.*.claim_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.submission_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.supporting_evidence_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.billed_amount' => $lineAmountRule,
            'lines.*.allowed_amount' => $lineAmountRule,
            'lines.*.paid_amount' => $lineAmountRule,
            'lines.*.denied_amount' => $lineAmountRule,
            'lines.*.adjusted_amount' => ['sometimes', 'nullable', 'string', 'regex:/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'lines.*.adjustment_reason' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:/\S/u'],
        ];
        if ($correction) {
            $rules['correction_reason'] = ['required', 'string', 'max:2000', 'regex:/\S/u'];
        } else {
            $rules['insurer_id'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['external_reference'] = InsuranceRemittanceRules::normalizeReference($data['external_reference']);
        $data['source_provenance'] = trim($data['source_provenance']);
        if ($correction) {
            $data['correction_reason'] = trim($data['correction_reason']);
        }
        foreach ($data['lines'] as &$line) {
            if (isset($line['external_line_reference'])) {
                $line['external_line_reference'] = InsuranceRemittanceRules::normalizeReference($line['external_line_reference']);
            }
            if (isset($line['adjustment_reason'])) {
                $line['adjustment_reason'] = trim($line['adjustment_reason']);
            }
        }
        unset($line);
        $allowedTop = ['external_reference', 'advice_date', 'communication_channel', 'source_provenance', 'currency', 'reported_totals', 'lines', $correction ? 'correction_reason' : 'insurer_id'];
        $unsupported = array_diff(array_keys($input), $allowedTop);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([reset($unsupported) => ['Unsupported remittance advice field.']]);
        }
        foreach ($data['lines'] as $line) {
            if (! InsuranceRemittanceRules::lineReferencesConsistent(
                $line['claim_id'] ?? null,
                $line['submission_id'] ?? null,
                $line['supporting_evidence_id'] ?? null,
            )) {
                throw ValidationException::withMessages(['lines' => ['Submission and supporting evidence references require their parent claim and submission.']]);
            }
        }
        if (isset($data['reported_totals']['adjusted'])) {
            $data['reported_totals']['adjusted'] = InsuranceRemittanceRules::amount($data['reported_totals']['adjusted'], true);
        }
        $lineRefs = array_map(fn ($line) => $line['external_line_reference'] ?? null, $data['lines']);
        if (! InsuranceRemittanceRules::uniqueLineReferences($lineRefs)) {
            throw ValidationException::withMessages(['lines' => ['External line references must be unique within the advice.']]);
        }

        return $data;
    }

    private function validateSources(array $data, User $actor): void
    {
        if (isset($data['insurer_id'])) {
            abort_unless(DB::table('insurers')->where('tenant_id', $this->context->requireId())->where('id', $data['insurer_id'])->exists(), 404);
        }
        foreach ($data['lines'] as $line) {
            $patient = Patient::query()->where('tenant_id', $this->context->requireId())->findOrFail($line['patient_id']);
            Gate::forUser($actor)->authorize('view', $patient);
            $association = PatientInsurerAssociation::query()->where('patient_id', $patient->id)
                ->where('insurer_id', $data['insurer_id'])
                ->findOrFail($line['association_id']);
            if (($line['claim_id'] ?? null) !== null) {
                $claim = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($line['claim_id']);
                abort_unless((int) $claim->association_id === (int) $association->id, 422, 'Claim and association must match the remittance line.');
                abort_unless(! DB::table('insurance_claim_lines')->where('tenant_id', $this->context->requireId())
                    ->where('claim_id', $claim->id)->where('currency', '<>', $data['currency'])->exists(), 422, 'Remittance currency must match its referenced claim lines.');
            }
            if (($line['submission_id'] ?? null) !== null) {
                abort_unless(DB::table('insurance_claim_submissions')->where('tenant_id', $this->context->requireId())
                    ->where('claim_id', $line['claim_id'])->where('id', $line['submission_id'])->exists(), 422, 'Submission must belong to the referenced claim.');
            }
            if (($line['supporting_evidence_id'] ?? null) !== null) {
                abort_unless(($line['submission_id'] ?? null) !== null && $actor->hasPermission('insurance_documents.view'), 403);
                $evidence = DB::table('insurance_prior_authorization_evidence')->where('tenant_id', $this->context->requireId())
                    ->where('id', $line['supporting_evidence_id'])->where('patient_id', $patient->id)
                    ->where('target_type', 'submission')->where('submission_id', $line['submission_id'])->first();
                abort_if($evidence === null, 404);
                $version = DocumentVersion::query()->findOrFail($evidence->version_id);
                $document = PatientDocument::withTrashed()->findOrFail($version->document_id);
                Gate::forUser($actor)->authorize('view', $document);
                $this->protection->assertReferenceable($document);
                $this->protection->assertReadable($document);
            }
        }
    }

    private function authorize(User $actor, string $action): void
    {
        abort_unless(InsuranceRemittanceAccessPolicy::allows($actor, $action), 403);
    }
}
