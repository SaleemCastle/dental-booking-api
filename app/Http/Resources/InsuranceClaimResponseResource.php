<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'claim_id' => $this->claim_id, 'submission_id' => $this->submission_id, 'response_type' => $this->response_type,
            'insurer_response_at' => $this->insurer_response_at?->toIso8601String(), 'recorded_at' => $this->recorded_at?->toIso8601String(), 'recorded_by_user_id' => $this->recorded_by_user_id,
            'communication_channel' => $this->communication_channel, 'insurer_reference' => $this->insurer_reference, 'source_provenance' => $this->source_provenance, 'notes' => $this->notes,
            'requested_information' => $this->requested_information, 'response_deadline' => $this->response_deadline?->format('Y-m-d'), 'evidence_id' => $this->evidence_id,
            'approved_amount' => $this->approved_amount === null ? null : (string) $this->approved_amount, 'currency' => $this->currency, 'staff_entered' => true];
    }
}
