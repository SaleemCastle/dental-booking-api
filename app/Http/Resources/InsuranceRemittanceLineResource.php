<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceRemittanceLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'external_line_reference' => $this->external_line_reference,
            'patient_id' => $this->patient_id,
            'association_id' => $this->association_id,
            'claim_id' => $this->claim_id,
            'submission_id' => $this->submission_id,
            'supporting_evidence_id' => $this->supporting_evidence_id,
            'billed_amount' => $this->billed_amount,
            'allowed_amount' => $this->allowed_amount,
            'paid_amount' => $this->paid_amount,
            'denied_amount' => $this->denied_amount,
            'adjusted_amount' => $this->adjusted_amount,
            'adjustment_reason' => $this->adjustment_reason,
        ];
    }
}
