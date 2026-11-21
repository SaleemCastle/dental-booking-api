<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimAppealResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appeal_id' => $this->appeal_id,
            'appeal_submission_id' => $this->appeal_submission_id,
            'response_type' => $this->response_type,
            'insurer_response_at' => $this->insurer_response_at?->toIso8601String(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'communication_channel' => $this->communication_channel,
            'source_provenance' => $this->source_provenance,
            'insurer_reference' => $this->insurer_reference,
            'notes' => $this->notes,
            'approved_amount' => $this->approved_amount === null ? null : (string) $this->approved_amount,
            'currency' => $this->currency,
            'staff_entered' => true,
        ];
    }
}
