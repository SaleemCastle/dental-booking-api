<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurancePlanVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'insurance_plan_id' => $this->insurance_plan_id,
            'version' => $this->version, 'lock_version' => $this->lock_version, 'status' => $this->status,
            'name' => $this->name, 'currency' => $this->currency,
            'effective_from' => $this->effective_from->toDateString(), 'effective_to' => $this->effective_to?->toDateString(),
            'source_reference' => $this->source_reference,
            'created_by_user_id' => $this->created_by_user_id, 'last_edited_by_user_id' => $this->last_edited_by_user_id,
            'reviewed_by_user_id' => $this->reviewed_by_user_id, 'reviewed_at' => $this->reviewed_at,
            'published_by_user_id' => $this->published_by_user_id, 'published_at' => $this->published_at,
            'benefits_configured' => false,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
