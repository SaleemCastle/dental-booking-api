<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientInsurancePolicyRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'policy_id' => $this->policy_id, 'association_id' => $this->association_id,
            'association_revision_id' => $this->association_revision_id, 'version' => $this->version,
            'policy_number_masked' => $this->mask($this->policy_number), 'membership_id_masked' => $this->mask($this->membership_id),
            'has_subscriber_name' => $this->subscriber_name !== null, 'relationship' => $this->relationship, 'plan_id' => $this->plan_id,
            'effective_date' => $this->effective_date, 'expiry_date' => $this->expiry_date,
            'actor_id' => $this->actor_id, 'recorded_at' => $this->recorded_at,
        ];
    }

    private function mask(?string $value): ?string
    {
        return $value === null ? null : '****'.(mb_strlen($value) > 4 ? mb_substr($value, -4) : '');
    }
}
