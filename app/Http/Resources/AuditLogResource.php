<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->request_metadata)
            ? array_filter(array_intersect_key($this->request_metadata, array_flip(['request_id', 'method', 'route'])), 'is_string')
            : null;

        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actorName' => $this->actor?->name,
            'action' => $this->action,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'occurred_at' => $this->occurred_at->utc()->toISOString(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_metadata' => $metadata === null ? null : (object) $metadata,
        ];
    }
}
