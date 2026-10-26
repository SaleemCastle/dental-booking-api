<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreatmentPlanItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'treatment_plan_id' => $this->treatment_plan_id,
            'treatment_id' => $this->treatment_id,
            'treatment_name' => $this->treatment_name,
            'dentist_id' => $this->dentist_id,
            'tooth_context' => $this->tooth_context,
            'catalog_price' => $this->catalog_price,
            'estimated_price' => $this->estimated_price,
            'sequence' => $this->sequence,
            'priority' => $this->priority,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
