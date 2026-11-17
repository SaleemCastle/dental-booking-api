<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimSubmissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'claim_id' => $this->claim_id, 'sequence' => $this->sequence,
            'channel' => $this->channel, 'channel_metadata' => $this->channel_metadata,
            'claim_version' => $this->claim_version, 'snapshot' => $this->snapshot,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at->toISOString(),
            'meaning' => 'Clinic staff recorded a submission action; insurer receipt has not been confirmed.',
        ];
    }
}
