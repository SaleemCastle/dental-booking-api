<?php

namespace App\Services;

use App\Http\Resources\PatientInsuranceCheckResource;
use App\Http\Resources\PatientInsurancePolicyRevisionResource;
use App\Http\Resources\PatientInsurerAssociationResource;
use App\Models\InsurancePlan;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PatientInsuranceCheck;
use App\Models\PatientInsurancePolicyRevision;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientInsuranceWorkflow
{
    private const PROVIDER_FIELDS = ['insurer_id', 'unverified_insurer_name', 'coverage_priority', 'active'];

    private const POLICY_FIELDS = ['policy_number', 'membership_id', 'subscriber_name', 'relationship', 'plan_id', 'effective_date', 'expiry_date'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    private function authorize(Patient $patient, User $actor, string $ability = 'view'): Patient
    {
        $this->context->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('patient_insurance.view')
            && ($ability === 'view' || $actor->hasPermission('patient_insurance.'.$ability)), 403);
        $patient = Patient::findOrFail($patient->id);
        Gate::forUser($actor)->authorize('view', $patient);

        return $patient;
    }

    private function association(Patient $patient, PatientInsurerAssociation $association, User $actor, string $ability = 'view', bool $lock = false): PatientInsurerAssociation
    {
        $this->authorize($patient, $actor, $ability);

        return PatientInsurerAssociation::query()->where('patient_id', $patient->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($association->id);
    }

    public function listing(Patient $patient, array $input, User $actor)
    {
        $this->authorize($patient, $actor);
        $page = $this->page(PatientInsurerAssociation::where('patient_id', $patient->id)->orderBy('id'), $input, PatientInsurerAssociationResource::class);
        $this->audit->record('listed', 'patient_insurance', $patient->id, $actor->id);

        return $page;
    }

    public function show(Patient $patient, PatientInsurerAssociation $association, User $actor): PatientInsurerAssociation
    {
        $association = $this->association($patient, $association, $actor);
        $this->audit->record('viewed', 'patient_insurance', $association->id, $actor->id);

        return $association;
    }

    public function create(Patient $patient, array $input, User $actor): PatientInsurerAssociation
    {
        $this->authorize($patient, $actor, 'manage');
        $data = $this->validate($input, $this->providerRules(false));

        return DB::transaction(function () use ($patient, $data, $actor) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $this->authorize($patient, $actor, 'manage');
            $values = $this->providerValues(['insurer_id' => null, 'unverified_insurer_name' => null, 'coverage_priority' => null, 'active' => true], $data, $actor);
            $association = new PatientInsurerAssociation;
            $association->forceFill([...$values, 'patient_id' => $patient->id, 'created_by_user_id' => $actor->id, 'lock_version' => 1])->save();
            $this->recordProviderRevision($association, $actor, 'Provider association recorded');
            $this->audit->record('created', 'patient_insurance', $association->id, $actor->id);

            return $association->refresh();
        });
    }

    public function update(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor): PatientInsurerAssociation
    {
        $this->association($patient, $association, $actor, 'manage');
        $data = $this->validate($input, $this->providerRules(true));
        $this->requireChange($data, self::PROVIDER_FIELDS);

        return DB::transaction(function () use ($patient, $association, $data, $actor) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $association = $this->association($patient, $association, $actor, 'manage', true);
            abort_unless($association->lock_version === (int) $data['expected_version'], 409);
            $values = $this->providerValues($association->only(self::PROVIDER_FIELDS), $data, $actor);
            $association->forceFill([...$values, 'lock_version' => $association->lock_version + 1])->save();
            $this->recordProviderRevision($association, $actor, $data['reason']);
            $this->audit->record('updated', 'patient_insurance', $association->id, $actor->id);

            return $association->refresh();
        });
    }

    private function providerRules(bool $update): array
    {
        return [
            'insurer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unverified_insurer_name' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\S/u'],
            'coverage_priority' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            ...($update ? ['active' => ['sometimes', 'boolean'], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']] : []),
        ];
    }

    private function providerValues(array $previous, array $data, User $actor): array
    {
        $values = array_replace($previous, array_intersect_key($data, array_flip(self::PROVIDER_FIELDS)));
        if (($values['insurer_id'] !== null) === ($values['unverified_insurer_name'] !== null)) {
            throw ValidationException::withMessages(['provider' => ['Supply exactly one insurer reference or unverified insurer name.']]);
        }
        if ($values['insurer_id'] !== null) {
            $insurer = Insurer::query()->lockForUpdate()->findOrFail($values['insurer_id']);
            Gate::forUser($actor)->authorize('view', $insurer);
        }

        return $values;
    }

    private function recordProviderRevision(PatientInsurerAssociation $association, User $actor, string $reason): void
    {
        DB::table('patient_insurer_revisions')->insert([
            'tenant_id' => $association->tenant_id, 'association_id' => $association->id, 'version' => $association->lock_version,
            ...$association->only(self::PROVIDER_FIELDS), 'actor_id' => $actor->id, 'reason' => Crypt::encryptString($reason),
        ]);
    }

    private function currentProviderRevision(PatientInsurerAssociation $association): int
    {
        return (int) DB::table('patient_insurer_revisions')->where('tenant_id', $association->tenant_id)->where('association_id', $association->id)
            ->where('version', $association->lock_version)->sole()->id;
    }

    public function history(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor)
    {
        $association = $this->association($patient, $association, $actor);
        $filters = $this->pagination($input);
        $page = DB::table('patient_insurer_revisions')->where('tenant_id', $association->tenant_id)->where('association_id', $association->id)
            ->select(['id', 'association_id', 'version', ...self::PROVIDER_FIELDS, 'actor_id', 'recorded_at'])->orderByDesc('version')->paginate($filters['per_page'] ?? 15);
        $this->audit->record('history_viewed', 'patient_insurance', $association->id, $actor->id);

        return $page;
    }

    public function policyRevisions(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor)
    {
        $association = $this->association($patient, $association, $actor);
        $page = $this->page(PatientInsurancePolicyRevision::where('association_id', $association->id)->orderByDesc('version'), $input, PatientInsurancePolicyRevisionResource::class);
        $this->audit->record('policies_viewed', 'patient_insurance', $association->id, $actor->id);

        return $page;
    }

    public function revisePolicy(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor): PatientInsurancePolicyRevision
    {
        $this->association($patient, $association, $actor, 'manage');
        $data = $this->validate($input, [
            'expected_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'policy_number' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\S/u'],
            'membership_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\S/u'],
            'subscriber_name' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\S/u'],
            'relationship' => ['sometimes', 'nullable', Rule::in(['self', 'spouse', 'child', 'other'])],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'effective_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $this->requireChange($data, self::POLICY_FIELDS);

        return DB::transaction(function () use ($patient, $association, $data, $actor) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $association = $this->association($patient, $association, $actor, 'manage', true);
            $policy = DB::table('patient_insurance_policies')->where('tenant_id', $association->tenant_id)->where('association_id', $association->id)->lockForUpdate()->first();
            abort_unless((int) ($policy->lock_version ?? 0) === (int) $data['expected_version'], 409);
            $previous = $policy === null ? null : PatientInsurancePolicyRevision::where('policy_id', $policy->id)->where('version', $policy->lock_version)->firstOrFail();
            $values = array_replace(array_fill_keys(self::POLICY_FIELDS, null), $previous?->only(self::POLICY_FIELDS) ?? [], array_intersect_key($data, array_flip(self::POLICY_FIELDS)));
            if ($previous === null && count(array_filter($values, fn ($v) => $v !== null)) === 0) {
                throw ValidationException::withMessages(['policy' => ['Supply at least one known policy detail.']]);
            }
            if ($values['effective_date'] !== null && $values['expiry_date'] !== null && $values['effective_date'] > $values['expiry_date']) {
                throw ValidationException::withMessages(['expiry_date' => ['Expiry must not precede the effective date.']]);
            }
            if ($values['plan_id'] !== null) {
                $plan = InsurancePlan::query()->where('insurer_id', $association->insurer_id)->lockForUpdate()->findOrFail($values['plan_id']);
                Gate::forUser($actor)->authorize('view', $plan);
            }
            if ($policy === null) {
                $policyId = DB::table('patient_insurance_policies')->insertGetId(['tenant_id' => $association->tenant_id, 'association_id' => $association->id, 'created_by_user_id' => $actor->id]);
                $version = 1;
            } else {
                $policyId = $policy->id;
                $version = $policy->lock_version + 1;
                DB::table('patient_insurance_policies')->where('id', $policyId)->update(['lock_version' => $version]);
            }
            $revision = new PatientInsurancePolicyRevision;
            $revision->forceFill([
                ...$values, 'association_id' => $association->id, 'association_revision_id' => $this->currentProviderRevision($association),
                'policy_id' => $policyId, 'version' => $version, 'reason' => $data['reason'], 'actor_id' => $actor->id,
            ])->save();
            $this->audit->record('policy_revised', 'patient_insurance', $association->id, $actor->id);

            return $revision->refresh();
        });
    }

    public function revealPolicy(Patient $patient, PatientInsurerAssociation $association, int $revisionId, User $actor): array
    {
        $association = $this->association($patient, $association, $actor, 'reveal');
        $revision = PatientInsurancePolicyRevision::where('association_id', $association->id)->findOrFail($revisionId);
        $this->audit->record('identifiers_revealed', 'patient_insurance_policy_revisions', $revision->id, $actor->id);

        return $revision->only(['policy_number', 'membership_id', 'subscriber_name', 'reason']);
    }

    public function checks(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor)
    {
        $association = $this->association($patient, $association, $actor);
        $page = $this->page(PatientInsuranceCheck::where('association_id', $association->id)->orderByDesc('id'), $input, PatientInsuranceCheckResource::class);
        $this->audit->record('checks_viewed', 'patient_insurance', $association->id, $actor->id);

        return $page;
    }

    public function recordCheck(Patient $patient, PatientInsurerAssociation $association, array $input, User $actor): PatientInsuranceCheck
    {
        $this->association($patient, $association, $actor, 'manage');
        $data = $this->validate($input, [
            'outcome' => ['required', Rule::in(['unknown', 'active', 'inactive', 'unable_to_verify'])],
            'channel' => ['required', Rule::in(['external_card', 'external_portal', 'telephone', 'paper', 'other'])],
            'checked_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\S/u'],
            'policy_revision_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'service_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return DB::transaction(function () use ($patient, $association, $data, $actor) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $association = $this->association($patient, $association, $actor, 'manage', true);
            if (isset($data['policy_revision_id'])) {
                PatientInsurancePolicyRevision::where('association_id', $association->id)->findOrFail($data['policy_revision_id']);
            }
            $check = new PatientInsuranceCheck;
            $check->forceFill([...$data, 'checked_at' => Carbon::parse($data['checked_at'])->utc(), 'association_id' => $association->id,
                'association_revision_id' => $this->currentProviderRevision($association), 'actor_id' => $actor->id])->save();
            $this->audit->record('external_check_recorded', 'patient_insurance_checks', $check->id, $actor->id);

            return $check->refresh();
        });
    }

    public function revealCheck(Patient $patient, PatientInsurerAssociation $association, int $checkId, User $actor): array
    {
        $association = $this->association($patient, $association, $actor, 'reveal');
        $check = PatientInsuranceCheck::where('association_id', $association->id)->findOrFail($checkId);
        $this->audit->record('check_details_revealed', 'patient_insurance_checks', $check->id, $actor->id);

        return $check->only(['provenance', 'reference']);
    }

    private function requireChange(array $data, array $fields): void
    {
        if (array_intersect(array_keys($data), $fields) === []) {
            throw ValidationException::withMessages(['request' => ['Supply at least one insurance field to change.']]);
        }
    }

    private function validate(array $input, array $rules): array
    {
        foreach (array_diff(array_keys($input), array_keys($rules)) as $field) {
            throw ValidationException::withMessages([$field => ['Unsupported patient insurance field.']]);
        }
        $input = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $input);

        return Validator::make($input, $rules)->validate();
    }

    private function pagination(array $input): array
    {
        return $this->validate($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
    }

    private function page(Builder $query, array $input, string $resource)
    {
        $filters = $this->pagination($input);
        $page = $query->paginate($filters['per_page'] ?? 15);
        $page->setCollection($page->getCollection()->map(fn ($model) => new $resource($model)));

        return $page;
    }
}
