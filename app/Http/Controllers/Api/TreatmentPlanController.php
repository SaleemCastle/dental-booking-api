<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TreatmentPlanItemRequest;
use App\Http\Requests\TreatmentPlanRequest;
use App\Http\Resources\TreatmentPlanItemResource;
use App\Http\Resources\TreatmentPlanResource;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Services\TreatmentPlanWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreatmentPlanController extends Controller
{
    public function index(Request $request, Patient $patient)
    {
        $this->authorize('viewAny', TreatmentPlan::class);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(TreatmentPlan::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $plans = $patient->treatmentPlans()->with('items')
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $plans->setCollection($plans->getCollection()->map(fn ($plan) => new TreatmentPlanResource($plan)));

        return ApiResponse::success($plans, 'Treatment plans retrieved.')->header('Cache-Control', 'private, no-store');
    }

    public function store(TreatmentPlanRequest $request, Patient $patient, TreatmentPlanWorkflow $workflow)
    {
        return $this->planResponse($workflow->create($patient, $request->validated(), $request->user()), 201);
    }

    public function show(TreatmentPlan $treatmentPlan)
    {
        $this->authorize('view', $treatmentPlan);

        return $this->planResponse($treatmentPlan->load('items'));
    }

    public function update(TreatmentPlanRequest $request, TreatmentPlan $treatmentPlan, TreatmentPlanWorkflow $workflow)
    {
        return $this->planResponse($workflow->update($treatmentPlan, $request->validated(), $request->user()));
    }

    public function storeItem(TreatmentPlanItemRequest $request, TreatmentPlan $treatmentPlan, TreatmentPlanWorkflow $workflow)
    {
        $item = $workflow->addItem($treatmentPlan, $request->validated(), $request->user());

        return ApiResponse::success(['treatmentPlanItem' => new TreatmentPlanItemResource($item)], 'Treatment plan item created.', 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateItem(TreatmentPlanItemRequest $request, TreatmentPlan $treatmentPlan, int $item, TreatmentPlanWorkflow $workflow)
    {
        $item = $treatmentPlan->items()->findOrFail($item);
        $item = $workflow->updateItem($treatmentPlan, $item, $request->validated(), $request->user());

        return ApiResponse::success(['treatmentPlanItem' => new TreatmentPlanItemResource($item)], 'Treatment plan item updated.')
            ->header('Cache-Control', 'private, no-store');
    }

    private function planResponse(TreatmentPlan $plan, int $status = 200)
    {
        return ApiResponse::success(['treatmentPlan' => new TreatmentPlanResource($plan)], 'Treatment plan retrieved.', $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
