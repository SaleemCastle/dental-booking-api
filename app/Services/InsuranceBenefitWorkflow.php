<?php

namespace App\Services;

use App\Http\Resources\InsuranceBenefitRuleResource;
use App\Insurance\BenefitInput;
use App\Models\InsuranceBenefitRule;
use App\Models\InsurancePlan;
use App\Models\InsurancePlanVersion;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PatientInsurancePolicyRevision;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InsuranceBenefitWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    private function parent(InsurancePlanVersion|PatientInsurerAssociation $parent, User $actor, string $ability, ?Patient $patient, bool $lock = false): InsurancePlanVersion|PatientInsurerAssociation
    {
        $this->context->requireId();
        $plan = $parent instanceof InsurancePlanVersion;
        $resource = $plan ? 'insurance_catalog' : 'patient_insurance';
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission($resource.'.view')
            && ($ability === 'view' || $actor->hasPermission($resource.'.'.$ability)), 403);
        if ($plan) {
            $parent = InsurancePlanVersion::findOrFail($parent->id);
            if ($lock) {
                // Same order as catalog publication: insurer -> plan -> version.
                $catalogPlan = InsurancePlan::findOrFail($parent->insurance_plan_id);
                Insurer::whereKey($catalogPlan->insurer_id)->lockForUpdate()->firstOrFail();
                InsurancePlan::whereKey($catalogPlan->id)->lockForUpdate()->firstOrFail();
            }

            return InsurancePlanVersion::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($parent->id);
        }
        abort_unless($patient !== null, 404);
        $patient = Patient::findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);
        if ($lock) {
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
        }

        return PatientInsurerAssociation::where('patient_id', $patient->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($parent->id);
    }

    private function query(InsurancePlanVersion|PatientInsurerAssociation $parent)
    {
        return InsuranceBenefitRule::where($parent instanceof InsurancePlanVersion ? 'plan_version_id' : 'association_id', $parent->id);
    }

    public function listing(InsurancePlanVersion|PatientInsurerAssociation $parent, array $input, User $actor, ?Patient $patient = null)
    {
        $parent = $this->parent($parent, $actor, 'view', $patient);
        BenefitInput::only($input, ['page', 'per_page']);
        $filters = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']])->validate();
        $page = $this->query($parent)->orderByDesc('id')->paginate($filters['per_page'] ?? 15);
        $page->setCollection($page->getCollection()->map(fn ($rule) => new InsuranceBenefitRuleResource($rule)));
        $this->audit->record('benefits_viewed', $parent->getTable(), $parent->id, $actor->id);

        return $page;
    }

    public function record(InsurancePlanVersion|PatientInsurerAssociation $parent, array $input, User $actor, ?Patient $patient = null): InsuranceBenefitRule
    {
        $this->parent($parent, $actor, 'manage', $patient);
        $plan = $parent instanceof InsurancePlanVersion;
        $data = BenefitInput::validate($input, $plan);

        return DB::transaction(function () use ($parent, $data, $actor, $patient, $plan) {
            $parent = $this->parent($parent, $actor, 'manage', $patient, true);
            if ($plan) {
                abort_unless($parent->status === 'draft' && $parent->lock_version === (int) $data['expected_version'], 409);
                abort_unless($parent->plan->active && $parent->plan->insurer->active, 409);
                if (isset($data['currency']) && $data['currency'] !== $parent->currency) {
                    throw ValidationException::withMessages(['currency' => ['Currency must match the plan version.']]);
                }
                $owner = ['plan_version_id' => $parent->id];
            } else {
                if (isset($data['policy_revision_id'])) {
                    PatientInsurancePolicyRevision::where('association_id', $parent->id)->findOrFail($data['policy_revision_id']);
                }
                $revision = DB::table('patient_insurer_revisions')->where('tenant_id', $parent->tenant_id)
                    ->where('association_id', $parent->id)->where('version', $parent->lock_version)->sole();
                $owner = ['association_id' => $parent->id, 'association_revision_id' => $revision->id];
            }
            $previous = isset($data['supersedes_id']) ? $this->query($parent)->findOrFail($data['supersedes_id']) : null;
            if ($previous !== null) {
                abort_if(InsuranceBenefitRule::where('supersedes_id', $previous->id)->exists(), 409);
            }
            $rule = new InsuranceBenefitRule;
            $rule->forceFill([
                ...array_diff_key($data, ['expected_version' => true]), ...$owner,
                'revision' => $previous === null ? 1 : $previous->revision + 1,
                'observed_at' => Carbon::parse($data['observed_at'])->utc(), 'actor_id' => $actor->id,
            ])->save();
            $this->audit->record($previous === null ? 'benefit_recorded' : 'benefit_corrected', 'insurance_benefit_rules', $rule->id, $actor->id);

            return $rule->refresh();
        });
    }

    public function details(InsurancePlanVersion|PatientInsurerAssociation $parent, int $id, User $actor, ?Patient $patient = null): array
    {
        $parent = $this->parent($parent, $actor, $parent instanceof InsurancePlanVersion ? 'manage' : 'reveal', $patient);
        $rule = $this->query($parent)->findOrFail($id);
        $this->audit->record('benefit_details_revealed', 'insurance_benefit_rules', $rule->id, $actor->id);

        return $rule->only(['provenance', 'reference', 'reason']);
    }
}
