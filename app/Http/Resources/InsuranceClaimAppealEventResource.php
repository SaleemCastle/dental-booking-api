<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimAppealEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'from_status' => $this->from_status, 'to_status' => $this->to_status, 'event_type' => $this->event_type, 'actor_id' => $this->actor_id, 'occurred_at' => $this->occurred_at?->toIso8601String()];
    }
}
