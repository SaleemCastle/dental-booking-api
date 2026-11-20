<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'claim_id' => $this->claim_id, 'version' => $this->version,
            'from_status' => $this->from_status, 'to_status' => $this->to_status,
            'event_type' => $this->event_type, 'reason' => $this->reason,
            'actor_id' => $this->actor_id, 'occurred_at' => $this->occurred_at->toISOString(),
        ];
    }
}
