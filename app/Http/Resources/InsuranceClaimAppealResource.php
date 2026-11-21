<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimAppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'claim_id' => $this->claim_id, 'submission_id' => $this->submission_id, 'decision_response_id' => $this->decision_response_id,
            'appeal_reason' => $this->appeal_reason, 'status' => $this->status, 'created_by_user_id' => $this->created_by_user_id, 'created_at' => $this->created_at?->toIso8601String(),
            'events' => InsuranceClaimAppealEventResource::collection($this->whenLoaded('events')),
            'submissions' => $this->whenLoaded('submissions', fn () => $this->submissions->map(fn ($s) => ['id' => $s->id, 'sequence' => $s->sequence, 'channel' => $s->channel, 'snapshot' => $s->snapshot, 'submitted_by_user_id' => $s->submitted_by_user_id, 'submitted_at' => $s->submitted_at?->toIso8601String()])),
            'responses' => $this->whenLoaded('responses', fn () => $this->responses->map(fn ($r) => ['id' => $r->id, 'appeal_submission_id' => $r->appeal_submission_id, 'response_type' => $r->response_type, 'insurer_response_at' => $r->insurer_response_at?->toIso8601String(), 'recorded_at' => $r->recorded_at?->toIso8601String(), 'recorded_by_user_id' => $r->recorded_by_user_id, 'communication_channel' => $r->communication_channel, 'source_provenance' => $r->source_provenance, 'insurer_reference' => $r->insurer_reference, 'notes' => $r->notes, 'approved_amount' => $r->approved_amount === null ? null : (string) $r->approved_amount, 'currency' => $r->currency, 'staff_entered' => true]))];
    }
}
