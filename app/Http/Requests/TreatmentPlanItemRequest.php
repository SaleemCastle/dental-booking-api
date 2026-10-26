<?php

namespace App\Http\Requests;

use App\Dental\DentalChartSchema;
use App\Models\TreatmentPlanItem;
use App\Tenancy\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TreatmentPlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('treatment_plan'));
    }

    public function rules(): array
    {
        return self::itemRules(updating: ! $this->isMethod('post'));
    }

    public static function itemRules(string $prefix = '', bool $updating = false): array
    {
        return [
            $prefix.'treatment_id' => $updating ? ['missing'] : ['required', 'integer', TenantRules::exists('treatments')],
            $prefix.'dentist_id' => [$updating ? 'sometimes' : 'required', 'required', 'integer', TenantRules::exists('dentist')],
            $prefix.'estimated_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            $prefix.'sequence' => ['sometimes', 'required', 'integer', 'between:1,1000000'],
            $prefix.'priority' => ['sometimes', 'required', Rule::in(TreatmentPlanItem::PRIORITIES)],
            $prefix.'status' => $updating ? ['sometimes', 'required', Rule::in(TreatmentPlanItem::STATUSES)] : ['missing'],
            $prefix.'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            $prefix.'tooth_context' => ['sometimes', 'nullable', 'array:dentition,numbering_system,tooth,surfaces'],
            $prefix.'tooth_context.dentition' => ['required_with:'.$prefix.'tooth_context', Rule::in(DentalChartSchema::DENTITIONS)],
            $prefix.'tooth_context.numbering_system' => ['sometimes', 'required', Rule::in(DentalChartSchema::NUMBERING_SYSTEMS)],
            $prefix.'tooth_context.tooth' => ['required_with:'.$prefix.'tooth_context'],
            $prefix.'tooth_context.surfaces' => ['sometimes', 'array', 'max:5'],
            $prefix.'tooth_context.surfaces.*' => ['required', 'string', Rule::in([...DentalChartSchema::SURFACES, 'F', 'P'])],
            $prefix.'tenant_id' => ['missing'],
            $prefix.'treatment_plan_id' => ['missing'],
            $prefix.'catalog_price' => ['missing'],
            $prefix.'treatment_name' => ['missing'],
        ];
    }
}
