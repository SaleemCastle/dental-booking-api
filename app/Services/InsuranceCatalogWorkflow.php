<?php

namespace App\Services;

use App\Insurance\CatalogInput;
use App\Models\InsurancePlan;
use App\Models\InsurancePlanVersion;
use App\Models\Insurer;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InsuranceCatalogWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $auditLogs) {}

    public function insurers(array $filters, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'viewAny', Insurer::class);
        $filters = $this->filters($filters);

        return Insurer::query()->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']))
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);
    }

    public function insurer(Insurer $insurer, User $actor): Insurer
    {
        $this->authorize($actor, 'view', $insurer);

        return Insurer::query()->findOrFail($insurer->id);
    }

    public function plans(Insurer $insurer, array $filters, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view', $insurer);
        $this->authorize($actor, 'viewAny', InsurancePlan::class);
        $filters = $this->filters($filters);

        return InsurancePlan::query()->where('insurer_id', $insurer->id)
            ->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']))
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);
    }

    public function plan(InsurancePlan $plan, User $actor): InsurancePlan
    {
        $this->authorize($actor, 'view', $plan);

        return InsurancePlan::query()->findOrFail($plan->id);
    }

    public function versions(InsurancePlan $plan, array $filters, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view', $plan);
        $this->authorize($actor, 'viewAny', InsurancePlanVersion::class);
        $filters = Validator::make($filters, [
            'status' => ['sometimes', Rule::in(['draft', 'reviewed', 'published', 'withdrawn'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ])->validate();

        return InsurancePlanVersion::query()->where('insurance_plan_id', $plan->id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('version')->paginate($filters['per_page'] ?? 25);
    }

    public function version(InsurancePlanVersion $version, User $actor): InsurancePlanVersion
    {
        $this->authorize($actor, 'view', $version);

        return InsurancePlanVersion::query()->findOrFail($version->id);
    }

    public function createInsurer(array $data, User $actor): Insurer
    {
        $this->authorize($actor, 'create', Insurer::class);
        $data = CatalogInput::validate('storeInsurer', $data);

        return $this->write(function () use ($data, $actor) {
            $insurer = new Insurer;
            $insurer->forceFill([...$data, 'lock_version' => 1])->save();
            $this->audit('created', $insurer, $actor);

            return $insurer->refresh();
        });
    }

    public function updateInsurer(Insurer $insurer, array $data, User $actor, bool $archive = false): Insurer
    {
        $this->authorize($actor, 'update', $insurer);

        return $this->write(function () use ($insurer, $data, $actor, $archive) {
            $insurer = Insurer::query()->lockForUpdate()->findOrFail($insurer->id);
            $data = CatalogInput::validate($archive ? 'archiveInsurer' : 'updateInsurer', $data, $insurer);
            $this->expected($insurer, $data);
            $insurer->forceFill($archive ? ['active' => false] : array_diff_key($data, ['expected_version' => true]));
            $insurer->lock_version++;
            $insurer->save();
            $this->audit($archive ? 'archived' : 'updated', $insurer, $actor);

            return $insurer->refresh();
        });
    }

    public function createPlan(Insurer $insurer, array $data, User $actor): InsurancePlan
    {
        $this->authorize($actor, 'update', $insurer);
        $this->authorize($actor, 'create', InsurancePlan::class);

        return $this->write(function () use ($insurer, $data, $actor) {
            $insurer = Insurer::query()->lockForUpdate()->findOrFail($insurer->id);
            abort_unless($insurer->active, 409);
            $data = CatalogInput::validate('storePlan', $data, $insurer);
            $plan = new InsurancePlan;
            $plan->forceFill([...$data, 'insurer_id' => $insurer->id, 'lock_version' => 1])->save();
            $this->audit('created', $plan, $actor);

            return $plan->refresh();
        });
    }

    public function updatePlan(InsurancePlan $plan, array $data, User $actor, bool $archive = false): InsurancePlan
    {
        $this->authorize($actor, 'update', $plan);

        return $this->write(function () use ($plan, $data, $actor, $archive) {
            $plan = $this->lockPlan($plan);
            $data = CatalogInput::validate($archive ? 'archivePlan' : 'updatePlan', $data, $plan);
            $this->expected($plan, $data);
            $plan->forceFill($archive ? ['active' => false] : array_diff_key($data, ['expected_version' => true]));
            $plan->lock_version++;
            $plan->save();
            $this->audit($archive ? 'archived' : 'updated', $plan, $actor);

            return $plan->refresh();
        });
    }

    public function createVersion(InsurancePlan $plan, array $data, User $actor): InsurancePlanVersion
    {
        $this->authorize($actor, 'update', $plan);
        $this->authorize($actor, 'create', InsurancePlanVersion::class);

        return $this->write(function () use ($plan, $data, $actor) {
            $plan = $this->lockPlan($plan);
            $this->active($plan);
            $data = CatalogInput::validate('storeVersion', $data, $plan);
            $version = new InsurancePlanVersion;
            $version->forceFill([
                ...$data, 'insurance_plan_id' => $plan->id,
                'version' => ((int) $plan->versions()->max('version')) + 1,
                'lock_version' => 1, 'status' => 'draft',
                'created_by_user_id' => $actor->id, 'last_edited_by_user_id' => $actor->id,
            ])->save();
            $this->audit('created', $version, $actor);

            return $version->refresh();
        }, 'version');
    }

    public function updateVersion(InsurancePlanVersion $version, array $data, User $actor): InsurancePlanVersion
    {
        $this->authorize($actor, 'update', $version);
        $plan = InsurancePlan::query()->findOrFail($version->insurance_plan_id);

        return $this->write(function () use ($version, $plan, $data, $actor) {
            $version = $this->lockVersion($version, $plan);
            $this->active($version->plan);
            $data = CatalogInput::validate('updateVersion', $data, $version);
            $this->expected($version, $data);
            abort_unless($version->status === 'draft', 409);
            $version->forceFill(array_diff_key($data, ['expected_version' => true]));
            $version->last_edited_by_user_id = $actor->id;
            $version->lock_version++;
            $version->save();
            $this->audit('updated', $version, $actor);

            return $version->refresh();
        });
    }

    public function transition(InsurancePlanVersion $version, array $data, User $actor, string $action): InsurancePlanVersion
    {
        abort_unless(in_array($action, ['review', 'publish', 'withdraw'], true), 404);
        $ability = $action === 'withdraw' ? 'update' : $action;
        $this->authorize($actor, $ability, $version);
        $plan = InsurancePlan::query()->findOrFail($version->insurance_plan_id);

        return $this->write(function () use ($version, $plan, $data, $actor, $action, $ability) {
            $version = $this->lockVersion($version, $plan);
            $this->authorize($actor, $ability, $version);
            $data = CatalogInput::validate($action.'Version', $data, $version);
            $this->expected($version, $data);
            if ($action === 'withdraw') {
                abort_unless(in_array($version->status, ['draft', 'reviewed'], true), 409);
                $version->status = 'withdrawn';
            } else {
                $this->active($version->plan);
                abort_unless($version->status === ($action === 'review' ? 'draft' : 'reviewed'), 409);
                if ($action === 'publish') {
                    $overlap = InsurancePlanVersion::query()->where('insurance_plan_id', $version->insurance_plan_id)
                        ->where('status', 'published')->where('id', '!=', $version->id)
                        ->when($version->effective_to !== null, fn ($q) => $q->where('effective_from', '<=', $version->effective_to->toDateString()))
                        ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $version->effective_from->toDateString()))->exists();
                    abort_if($overlap, 409);
                }
                $version->status = $action === 'review' ? 'reviewed' : 'published';
                $version->{$action === 'review' ? 'reviewed_by_user_id' : 'published_by_user_id'} = $actor->id;
                $version->{$action === 'review' ? 'reviewed_at' : 'published_at'} = now('UTC');
            }
            $version->lock_version++;
            $version->save();
            $this->audit(match ($action) {
                'review' => 'reviewed', 'publish' => 'published', default => 'withdrawn'
            }, $version, $actor);

            return $version->refresh();
        }, 'version');
    }

    private function authorize(User $actor, string $ability, string|Model $resource): void
    {
        $this->context->requireId();
        Gate::forUser($actor)->authorize($ability, $resource);
    }

    private function lockPlan(InsurancePlan $plan): InsurancePlan
    {
        // Common catalog order: insurer -> plan -> version -> tracked editors.
        Insurer::query()->lockForUpdate()->findOrFail($plan->insurer_id);

        return InsurancePlan::query()->where('insurer_id', $plan->insurer_id)->lockForUpdate()->findOrFail($plan->id);
    }

    private function lockVersion(InsurancePlanVersion $version, InsurancePlan $plan): InsurancePlanVersion
    {
        $plan = $this->lockPlan($plan);

        return InsurancePlanVersion::query()->where('insurance_plan_id', $plan->id)->lockForUpdate()->findOrFail($version->id)->setRelation('plan', $plan);
    }

    private function active(InsurancePlan $plan): void
    {
        abort_unless($plan->active && $plan->insurer->active, 409);
    }

    private function expected(Model $record, array $data): void
    {
        abort_unless($record->lock_version === (int) $data['expected_version'], 409);
    }

    private function filters(array $filters): array
    {
        return Validator::make($filters, [
            'active' => ['sometimes', 'boolean'], 'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ])->validate();
    }

    private function audit(string $action, Model $record, User $actor): void
    {
        $this->auditLogs->record($action, $record->getTable(), $record->id, $actor->id);
    }

    private function write(Closure $callback, string $duplicateField = 'code'): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $error) {
            $state = $error->errorInfo[0] ?? null;
            if ($state === '23P01') {
                abort(409);
            }
            if ($state === '23505' || ($error->errorInfo[1] ?? null) === 1062 || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                throw ValidationException::withMessages([$duplicateField => ['This catalog identity already exists.']]);
            }
            throw $error;
        }
    }
}
