<?php

namespace App\Services;

use App\Dental\DentalChartSchema;
use App\Http\Requests\TreatmentPlanItemRequest;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TreatmentPlanWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(Patient $patient, array $data, User $actor): TreatmentPlan
    {
        Gate::forUser($actor)->authorize('create', TreatmentPlan::class);

        return DB::transaction(function () use ($patient, $data, $actor) {
            $patient = $this->writablePatient($patient->id);
            $plan = new TreatmentPlan;
            $plan->fill(Validator::make($data, [
                'title' => ['required', 'string', 'max:255'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ])->validate());
            $plan->forceFill(['patient_id' => $patient->id])->save();
            $this->auditLogs->record('created', 'treatment_plans', $plan->id, $actor->id);

            foreach ($data['items'] ?? [] as $index => $item) {
                $this->insertItem($plan, $item, $actor, 'items.'.$index.'.');
            }

            return $plan->refresh()->load('items');
        }, 3);
    }

    public function update(TreatmentPlan $plan, array $data, User $actor): TreatmentPlan
    {
        Gate::forUser($actor)->authorize('update', $plan);

        return DB::transaction(function () use ($plan, $data, $actor) {
            $plan = $this->writablePlan($plan);
            $status = $data['status'] ?? $plan->status;
            $allowed = ['draft' => ['active', 'cancelled'], 'active' => ['completed', 'cancelled']];
            if ($status !== $plan->status && ! in_array($status, $allowed[$plan->status] ?? [], true)) {
                $this->invalid('status', 'This plan status transition is not allowed.');
            }
            if ($status === 'active' && $plan->status !== 'active' && ! $plan->items()->where('status', 'proposed')->exists()) {
                $this->invalid('status', 'An active plan requires at least one proposed item.');
            }
            if ($status === 'completed' && ($plan->items()->whereIn('status', ['proposed', 'in_progress'])->exists()
                || ! $plan->items()->where('status', 'completed')->exists())) {
                $this->invalid('status', 'Complete all remaining items before completing the plan; at least one item must be completed.');
            }

            if ($status === 'cancelled') {
                foreach ($plan->items()->whereIn('status', ['proposed', 'in_progress'])->get() as $item) {
                    $item->update(['status' => 'cancelled']);
                    $this->auditLogs->record('updated', 'treatment_plan_items', $item->id, $actor->id);
                }
            }

            $plan->fill($data);
            if ($plan->isDirty()) {
                $plan->save();
                $this->auditLogs->record('updated', 'treatment_plans', $plan->id, $actor->id);
            }

            return $plan->refresh()->load('items');
        }, 3);
    }

    public function addItem(TreatmentPlan $plan, array $data, User $actor): TreatmentPlanItem
    {
        Gate::forUser($actor)->authorize('update', $plan);

        return DB::transaction(fn () => $this->insertItem($this->writablePlan($plan), $data, $actor), 3);
    }

    public function updateItem(TreatmentPlan $plan, TreatmentPlanItem $item, array $data, User $actor): TreatmentPlanItem
    {
        Gate::forUser($actor)->authorize('update', $plan);

        return DB::transaction(function () use ($plan, $item, $data, $actor) {
            $plan = $this->writablePlan($plan);
            $item = $plan->items()->lockForUpdate()->findOrFail($item->id);
            if (in_array($item->status, ['completed', 'cancelled'], true)) {
                $this->invalid('status', 'Completed and cancelled items are read-only.');
            }
            $data = $this->itemData($data, updating: true);
            $status = $data['status'] ?? $item->status;
            $allowed = ['proposed' => ['in_progress', 'completed', 'cancelled'], 'in_progress' => ['completed', 'cancelled']];
            if ($status !== $item->status && ! in_array($status, $allowed[$item->status] ?? [], true)) {
                $this->invalid('status', 'This item status transition is not allowed.');
            }
            if ($plan->status !== 'active' && in_array($status, ['in_progress', 'completed'], true)) {
                $this->invalid('status', 'Activate the plan before starting or completing an item.');
            }
            $item->fill($data);
            if ($item->isDirty()) {
                $item->save();
                $this->auditLogs->record('updated', 'treatment_plan_items', $item->id, $actor->id);
            }

            return $item->refresh();
        }, 3);
    }

    private function insertItem(TreatmentPlan $plan, array $data, User $actor, string $prefix = ''): TreatmentPlanItem
    {
        $data = $this->itemData($data, prefix: $prefix);
        $treatment = Treatment::query()->lockForUpdate()->findOrFail($data['treatment_id']);
        if (! $treatment->active) {
            $this->invalid($prefix.'treatment_id', 'Only active catalog procedures may be proposed.');
        }
        $sequence = $data['sequence'] ?? (($plan->items()->max('sequence') ?? 0) + 1);
        if ($sequence > 1000000) {
            $this->invalid($prefix.'sequence', 'Specify a sequence no greater than 1000000.');
        }
        $item = new TreatmentPlanItem($data);
        $item->forceFill([
            'treatment_plan_id' => $plan->id,
            'treatment_id' => $treatment->id,
            'treatment_name' => $treatment->name,
            'catalog_price' => $treatment->price,
            'estimated_price' => $data['estimated_price'] ?? $treatment->price,
            'sequence' => $sequence,
        ])->save();
        $this->auditLogs->record('created', 'treatment_plan_items', $item->id, $actor->id);

        return $item->refresh();
    }

    private function itemData(array $data, bool $updating = false, string $prefix = ''): array
    {
        $data = Validator::make($data, TreatmentPlanItemRequest::itemRules(updating: $updating))->validate();
        if (isset($data['dentist_id'])) {
            Dentist::query()->lockForUpdate()->findOrFail($data['dentist_id']);
        }
        if (isset($data['tooth_context'])) {
            $context = $data['tooth_context'];
            $dentition = $context['dentition'] ?? '';
            $system = $context['numbering_system'] ?? DentalChartSchema::DEFAULT_NUMBERING;
            $tooth = DentalChartSchema::toFdi($context['tooth'] ?? null, $dentition, $system);
            if (! in_array($dentition, DentalChartSchema::DENTITIONS, true) || $tooth === null) {
                $this->invalid($prefix.'tooth_context.tooth', 'Choose a valid tooth for the selected dentition and numbering system.');
            }
            $surfaces = array_map(DentalChartSchema::surface(...), $context['surfaces'] ?? []);
            if (count(array_unique($surfaces)) !== count($surfaces) || array_diff($surfaces, DentalChartSchema::surfacesFor($tooth))) {
                $this->invalid($prefix.'tooth_context.surfaces', 'Surfaces must be distinct and valid for this tooth.');
            }
            $data['tooth_context'] = ['dentition' => $dentition, 'numbering_system' => 'fdi', 'tooth' => $tooth, 'surfaces' => $surfaces];
        }

        return $data;
    }

    private function writablePlan(TreatmentPlan $plan): TreatmentPlan
    {
        // Use the same patient -> plan lock order for every mutation.
        $this->writablePatient($plan->patient_id);
        $plan = TreatmentPlan::query()->lockForUpdate()->findOrFail($plan->id);
        if (in_array($plan->status, ['completed', 'cancelled'], true)) {
            $this->invalid('status', 'Completed and cancelled plans are read-only.');
        }

        return $plan;
    }

    private function writablePatient(int $id): Patient
    {
        $patient = Patient::query()->lockForUpdate()->findOrFail($id);
        if ($patient->archived_at !== null) {
            $this->invalid('patient_id', 'Archived patients cannot receive treatment plan changes.');
        }

        return $patient;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
