<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurerObligationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $approval = $this->whenLoaded('approval');
        $approved = $approval !== null && ! $approval instanceof \Illuminate\Http\Resources\MissingValue;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'insurer_id' => $this->insurer_id,
            'association_id' => $this->association_id,
            'invoice_id' => $this->invoice_id,
            'invoice_item_id' => $this->invoice_item_id,
            'claim_id' => $this->claim_id,
            'submission_id' => $this->submission_id,
            'claim_response_id' => $this->claim_response_id,
            'supporting_evidence_id' => $this->supporting_evidence_id,
            'proposed_amount' => $this->proposed_amount,
            'currency' => $this->currency,
            'source_provenance' => $this->source_provenance,
            'proposed_by_user_id' => $this->proposed_by_user_id,
            'proposed_at' => $this->proposed_at,
            'approval_status' => $approved ? 'approved' : 'proposed',
            'approval' => $approved ? [
                'version' => 2,
                'approved_amount' => $approval->approved_amount,
                'currency' => $approval->currency,
                'approval_reason' => $approval->approval_reason,
                'approved_by_user_id' => $approval->approved_by_user_id,
                'approved_at' => $approval->approved_at,
            ] : null,
            'history' => array_values(array_filter([
                ['version' => 1, 'event' => 'proposed', 'recorded_by_user_id' => $this->proposed_by_user_id, 'occurred_at' => $this->proposed_at, 'amount' => $this->proposed_amount, 'currency' => $this->currency],
                $approved ? ['version' => 2, 'event' => 'approved', 'recorded_by_user_id' => $approval->approved_by_user_id, 'occurred_at' => $approval->approved_at, 'amount' => $approval->approved_amount, 'currency' => $approval->currency, 'reason' => $approval->approval_reason] : null,
            ])),
        ];
    }
}
