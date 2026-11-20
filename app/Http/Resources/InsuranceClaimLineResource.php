<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'treatment_plan_item_id' => $this->treatment_plan_item_id,
            'treatment_description' => $this->treatment_description, 'procedure_code' => $this->procedure_code,
            'code_system' => $this->code_system, 'service_date' => $this->service_date->format('Y-m-d'),
            'quantity' => $this->quantity, 'unit_amount' => (string) $this->unit_amount,
            'gross_amount' => (string) $this->gross_amount, 'currency' => $this->currency,
        ];
    }
}
