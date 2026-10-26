<?php

namespace App\Http\Requests;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('treatment_plan');

        return $plan ? $this->user()->can('update', $plan) : $this->user()->can('create', TreatmentPlan::class);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => $creating ? ['missing'] : ['sometimes', 'required', Rule::in(TreatmentPlan::STATUSES)],
            'items' => $creating ? ['sometimes', 'array', 'max:100'] : ['missing'],
            'items.*' => ['required', 'array'],
            ...TreatmentPlanItemRequest::itemRules('items.*.'),
            'tenant_id' => ['missing'],
            'patient_id' => ['missing'],
        ];
    }
}
