<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientInsurerAssociationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'patient_id' => $this->patient_id, 'insurer_id' => $this->insurer_id,
            'insurer_name' => $this->insurer?->name,
            'unverified_insurer_name' => $this->unverified_insurer_name,
            'provider_information_status' => $this->insurer_id === null ? 'unverified_name' : 'catalog_reference',
            'coverage_priority' => $this->coverage_priority, 'active' => $this->active, 'version' => $this->lock_version,
            'created_by_user_id' => $this->created_by_user_id, 'created_at' => $this->created_at,
        ];
    }
}
