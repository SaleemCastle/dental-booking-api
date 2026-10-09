<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'appointments' => $this->appointments,
            'streetAddress' => $this->streetAddress,
            'town' => $this->town,
            'city' => $this->city,
            'sex' => $this->sex,
            'notes' => $this->notes,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'allergies' => $this->allergies ?? [],
            'medications' => $this->medications ?? [],
            'medical_alerts' => $this->medical_alerts ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
