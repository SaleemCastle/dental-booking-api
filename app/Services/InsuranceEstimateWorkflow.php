<?php

namespace App\Services;

use App\Billing\Money;
use App\Insurance\EstimateCalculator;
use App\Models\InsuranceBenefitRule;
use App\Models\InsuranceEstimate;
use App\Models\InsurancePlanVersion;
use App\Models\Patient;
use App\Models\PatientInsurerAssociation;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InsuranceEstimateWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit, private readonly EstimateCalculator $calculator) {}

    public function create(Patient $patient, array $input, User $actor, ?InsuranceEstimate $supersedes = null, ?TreatmentPlanItem $treatmentPlanItem = null): InsuranceEstimate
    {
        $this->context->requireId();
        $this->authorize($patient, $actor, 'create');
        $allowed = ['association_id', 'service_date', 'treatment_description', 'procedure_code', 'code_system', 'gross_amount', 'currency', 'benefit_rule_id'];
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            throw ValidationException::withMessages([$field => ['Unsupported estimate field.']]);
        }
        $data = Validator::make($input, [
            'association_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'treatment_description' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'procedure_code' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/\S/u'],
            'code_system' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/\S/u'],
            'gross_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'benefit_rule_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();
        if (isset($data['procedure_code']) !== isset($data['code_system'])) {
            throw ValidationException::withMessages(['code_system' => ['A code system must accompany an explicitly supplied procedure code.']]);
        }
        try { $gross = Money::minor($data['gross_amount']); } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['gross_amount' => ['Use a nonnegative amount with at most two decimal places within supported billing precision.']]);
        }
        $patient = Patient::query()->findOrFail($patient->id);
        $association = isset($data['association_id']) ? PatientInsurerAssociation::where('patient_id', $patient->id)->findOrFail($data['association_id']) : null;
        if ($supersedes !== null && ((int) $supersedes->patient_id !== (int) $patient->id || (int) $supersedes->tenant_id !== $this->context->requireId())) {
            abort(404);
        }
        $rule = isset($data['benefit_rule_id']) ? InsuranceBenefitRule::findOrFail($data['benefit_rule_id']) : null;
        if ($rule !== null) {
            $this->validateRule($rule, $association, $data, $patient);
        }
        $calculation = $this->calculator->calculate($gross, $data['currency'], $rule);
        if ($rule?->scope === 'procedure' && ($rule->procedure_code !== ($data['procedure_code'] ?? null)
            || $rule->code_system !== ($data['code_system'] ?? null))) {
            $calculation = [...$calculation, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['PROCEDURE_CODE_MISMATCH'], 'assumptions' => []];
            unset($calculation['trace']);
        }
        if ($rule !== null && $this->hasConflict($rule, $data['service_date'], $association)) {
            $calculation = [...$calculation, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['CONFLICTING_BENEFIT_OBSERVATIONS'], 'assumptions' => []];
            unset($calculation['trace']);
        }

        if ($treatmentPlanItem !== null) {
            $calculation['source_snapshot'] = ['type' => 'treatment_plan_item', 'treatment_plan_item_id' => $treatmentPlanItem->id,
                'treatment_plan_id' => $treatmentPlanItem->treatment_plan_id, 'estimated_price' => (string) $treatmentPlanItem->estimated_price,
                'treatment_name' => $treatmentPlanItem->treatment_name, 'source_updated_at' => $treatmentPlanItem->updated_at?->toISOString()];
        }
        return DB::transaction(function () use ($patient, $association, $rule, $data, $gross, $calculation, $actor, $supersedes, $treatmentPlanItem) {
            if ($supersedes !== null) {
                InsuranceEstimate::whereKey($supersedes->id)->lockForUpdate()->firstOrFail();
                abort_if(InsuranceEstimate::where('supersedes_id', $supersedes->id)->exists(), 409);
            }
            $estimate = new InsuranceEstimate;
            $estimate->forceFill(['patient_id' => $patient->id, 'association_id' => $association?->id,
                'benefit_rule_id' => $rule?->id, 'supersedes_id' => $supersedes?->id,
                'treatment_plan_item_id' => $treatmentPlanItem?->id,
                'service_date' => $data['service_date'], 'treatment_description' => trim($data['treatment_description']),
                'procedure_code' => $data['procedure_code'] ?? null, 'code_system' => $data['code_system'] ?? null,
                'gross_amount' => Money::decimal($gross), 'currency' => $data['currency'], 'status' => $calculation['status'],
                'insurer_amount' => $calculation['insurer_amount'], 'patient_amount' => $calculation['patient_amount'],
                'calculation' => $calculation, 'created_by_user_id' => $actor->id])->save();
            $this->audit->record('estimate_created', 'insurance_estimates', $estimate->id, $actor->id);

            return $estimate->refresh();
        });
    }

    public function createForTreatmentPlanItem(TreatmentPlan $plan, int $itemId, array $input, User $actor): InsuranceEstimate
    {
        $this->context->requireId();
        abort_unless($actor->hasPermission('treatment_plans.view'), 403);
        \Illuminate\Support\Facades\Gate::forUser($actor)->authorize('view', $plan);
        foreach (array_diff(array_keys($input), ['association_id', 'benefit_rule_id', 'service_date', 'currency']) as $field) {
            throw ValidationException::withMessages([$field => ['Only association, selected benefit, service date, and currency may be supplied.']]);
        }
        return DB::transaction(function () use ($plan, $itemId, $input, $actor) {
            $item = TreatmentPlanItem::query()->lockForUpdate()->where('treatment_plan_id', $plan->id)->findOrFail($itemId);
            $patient = Patient::query()->findOrFail($plan->patient_id);
            abort_unless((int) $item->treatmentPlan->patient_id === (int) $patient->id, 404);
            $data = Validator::make($input, [
                'association_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'benefit_rule_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'service_date' => ['required', 'date_format:Y-m-d'],
                'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            ])->validate();
            return $this->create($patient, [
                'association_id' => $data['association_id'] ?? null,
                'benefit_rule_id' => $data['benefit_rule_id'] ?? null,
                'service_date' => $data['service_date'], 'treatment_description' => $item->treatment_name,
                'gross_amount' => (string) $item->estimated_price, 'currency' => $data['currency'],
            ], $actor, treatmentPlanItem: $item);
        }, 3);
    }

    public function listing(Patient $patient, User $actor, array $input)
    {
        $this->authorize($patient, $actor, 'view');
        $data = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'treatment_plan_id' => ['sometimes', 'integer', 'min:1'], 'latest_only' => ['sometimes', 'boolean']])->validate();
        $query = InsuranceEstimate::where('patient_id', $patient->id);
        if (($data['latest_only'] ?? false) && ! isset($data['treatment_plan_id'])) {
            throw ValidationException::withMessages(['latest_only' => ['The latest-only filter requires a treatment plan.']]);
        }
        if (isset($data['treatment_plan_id'])) {
            abort_unless($actor->hasPermission('treatment_plans.view'), 403);
            $plan = TreatmentPlan::query()->where('patient_id', $patient->id)->findOrFail($data['treatment_plan_id']);
            \Illuminate\Support\Facades\Gate::forUser($actor)->authorize('view', $plan);
            $items = $plan->items()->select('id');
            $query->whereIn('treatment_plan_item_id', $items);
            if ($data['latest_only'] ?? false) {
                $query->whereIn('id', InsuranceEstimate::query()->selectRaw('MAX(id)')->where('patient_id', $patient->id)
                    ->whereIn('treatment_plan_item_id', $plan->items()->select('id'))->groupBy('treatment_plan_item_id'));
            }
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $this->audit->record('estimates_viewed', 'patient', $patient->id, $actor->id);
        return $page;
    }

    public function show(Patient $patient, InsuranceEstimate $estimate, User $actor): InsuranceEstimate
    {
        $this->authorize($patient, $actor, 'view');
        abort_unless((int) $estimate->patient_id === (int) $patient->id, 404);
        $this->audit->record('estimate_viewed', 'insurance_estimates', $estimate->id, $actor->id);
        return $estimate;
    }

    private function authorize(Patient $patient, User $actor, string $action): void
    {
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('patients.view')
            && $actor->hasPermission('insurance_estimates.'.$action), 403);
        \Illuminate\Support\Facades\Gate::forUser($actor)->authorize('view', $patient);
    }

    private function validateRule(InsuranceBenefitRule $rule, ?PatientInsurerAssociation $association, array $data, Patient $patient): void
    {
        if ($rule->service_from !== null && $data['service_date'] < (string) $rule->service_from
            || $rule->service_to !== null && $data['service_date'] > (string) $rule->service_to) {
            throw ValidationException::withMessages(['benefit_rule_id' => ['The selected benefit rule does not apply on the service date.']]);
        }
        if ($rule->association_id !== null) {
            abort_unless($association !== null && (int) $rule->association_id === (int) $association->id
                && (int) $association->patient_id === (int) $patient->id, 404);
        }
        if ($rule->plan_version_id !== null) {
            $version = InsurancePlanVersion::findOrFail($rule->plan_version_id);
            abort_unless($version->status === 'published' && $version->effective_from->format('Y-m-d') <= $data['service_date']
                && ($version->effective_to === null || $version->effective_to->format('Y-m-d') >= $data['service_date']), 422);
            if ($association !== null && $association->insurer_id !== null) {
                abort_unless((int) $version->plan->insurer_id === (int) $association->insurer_id, 422);
            }
        }
        if ($rule->currency !== null && $rule->currency !== $data['currency']) {
            // Preserve a deterministic unresolved snapshot for a benefit currency mismatch.
            return;
        }
    }

    private function hasConflict(InsuranceBenefitRule $selected, string $date, ?PatientInsurerAssociation $association): bool
    {
        $query = InsuranceBenefitRule::query()->where(function ($q) use ($date) {
            $q->whereNull('service_from')->orWhereDate('service_from', '<=', $date);
        })->where(function ($q) use ($date) {
            $q->whereNull('service_to')->orWhereDate('service_to', '>=', $date);
        })->where('id', '<>', $selected->id)->where('scope', $selected->scope);
        $query->where(function ($owner) use ($selected, $association, $date) {
            if ($selected->plan_version_id !== null) $owner->where('plan_version_id', $selected->plan_version_id);
            else $owner->where('association_id', $selected->association_id);
            if ($association !== null && $selected->plan_version_id !== null) {
                $owner->orWhere('association_id', $association->id);
            } elseif ($selected->association_id !== null && $selected->policy_revision_id !== null) {
                $policy = DB::table('patient_insurance_policy_revisions')->where('id', $selected->policy_revision_id)->value('plan_id');
                if ($policy !== null) {
                    $owner->orWhereIn('plan_version_id', DB::table('insurance_plan_versions')->select('id')->where('insurance_plan_id', $policy)
                        ->where('status', 'published')->whereDate('effective_from', '<=', $date)
                        ->where(fn ($version) => $version->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date)));
                }
            }
        });
        if ($selected->scope === 'procedure') $query->where('procedure_code', $selected->procedure_code)->where('code_system', $selected->code_system);
        if ($selected->scope === 'category') $query->where('category', $selected->category);
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('insurance_benefit_rules as successor')->whereColumn('successor.supersedes_id', 'insurance_benefit_rules.id'));
        $comparedFields = ['coverage', 'benefit_type', 'percentage', 'amount', 'currency', 'annual_limit', 'visit_limit',
            'procedure_limit', 'waiting_period_days', 'prior_authorization_required'];
        return $query->get()->contains(fn ($rule) => $rule->only($comparedFields) !== $selected->only($comparedFields));
    }
}
