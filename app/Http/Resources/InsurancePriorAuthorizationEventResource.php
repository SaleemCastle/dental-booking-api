<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurancePriorAuthorizationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'from_status' => $this->from_status, 'to_status' => $this->to_status, 'event_type' => $this->event_type,
            'reason' => $this->reason, 'communication_channel' => $this->communication_channel, 'actor_id' => $this->actor_id, 'occurred_at' => $this->occurred_at?->toIso8601String()];
    }
}
