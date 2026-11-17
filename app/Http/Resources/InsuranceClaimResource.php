<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceClaimResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'claim_identifier' => $this->claim_identifier,
            'patient_id' => $this->patient_id, 'association_id' => $this->association_id,
            'policy_revision_id' => $this->policy_revision_id, 'estimate_id' => $this->estimate_id,
            'corrects_claim_id' => $this->corrects_claim_id, 'status' => $this->status,
            'lock_version' => $this->lock_version, 'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
            'lines' => $this->whenLoaded('lines', fn () => InsuranceClaimLineResource::collection($this->lines)),
        ];
    }
}
