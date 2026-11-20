<?php

namespace App\Http\Resources;

use App\Models\InsuranceBenefitRule;
use App\Models\InsuranceEstimate;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceEstimateResource extends JsonResource
{
    public function toArray($request): array
    {
        $superseded = InsuranceEstimate::query()->where('supersedes_id', $this->id)->exists();
        $staleReasons = [];
        if ($this->treatment_plan_item_id !== null) {
            $source = $this->treatmentPlanItem;
            if ($source === null || (string) $source->estimated_price !== (string) $this->gross_amount
                || $source->treatment_name !== $this->treatment_description) {
                $staleReasons[] = 'TREATMENT_SOURCE_CHANGED';
            }
        }
        if ($this->benefit_rule_id !== null && InsuranceBenefitRule::query()->where('supersedes_id', $this->benefit_rule_id)->exists()) {
            $staleReasons[] = 'BENEFIT_OBSERVATION_SUPERSEDED';
        }
        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'association_id' => $this->association_id,
            'service_date' => $this->service_date->format('Y-m-d'), 'treatment_description' => $this->treatment_description,
            'procedure_code' => $this->procedure_code, 'code_system' => $this->code_system,
            'gross_amount' => (string) $this->gross_amount, 'currency' => $this->currency, 'status' => $this->status,
            'insurer_amount' => $this->insurer_amount === null ? null : (string) $this->insurer_amount,
            'patient_amount' => $this->patient_amount === null ? null : (string) $this->patient_amount,
            'calculation' => $this->calculation, 'supersedes_id' => $this->supersedes_id,
            'treatment_plan_item_id' => $this->treatment_plan_item_id, 'provisional' => true,
            'superseded' => $superseded, 'stale' => $staleReasons !== [], 'stale_reason_codes' => $staleReasons,
            'created_by_user_id' => $this->created_by_user_id, 'recorded_at' => $this->recorded_at->toISOString()];
    }
}
