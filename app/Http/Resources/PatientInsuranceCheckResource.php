<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientInsuranceCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'association_id' => $this->association_id, 'association_revision_id' => $this->association_revision_id,
            'policy_revision_id' => $this->policy_revision_id, 'outcome' => $this->outcome, 'channel' => $this->channel,
            'source' => 'staff_recorded_external', 'checked_at' => $this->checked_at?->toISOString(),
            'service_date' => $this->service_date, 'has_reference' => $this->reference !== null,
            'actor_id' => $this->actor_id, 'recorded_at' => $this->recorded_at,
        ];
    }
}
