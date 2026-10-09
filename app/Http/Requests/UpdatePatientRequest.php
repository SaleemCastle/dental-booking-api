<?php

namespace App\Http\Requests;

class UpdatePatientRequest extends StorePatientRequest
{
    public function rules(): array
    {
        return [
            'firstName' => ['sometimes', 'required', 'string', 'max:100'],
            'lastName' => ['sometimes', 'required', 'string', 'max:100'],
            'appointments' => ['sometimes', 'required', 'string', 'max:255'],
            'sex' => ['sometimes', 'required', 'string', 'max:255'],
            'streetAddress' => ['sometimes', 'required', 'string', 'max:255'],
            'town' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'required', 'string'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:100'],
            'allergies' => ['sometimes', 'nullable', 'array'],
            'allergies.*' => ['string', 'max:255'],
            'medications' => ['sometimes', 'nullable', 'array'],
            'medications.*' => ['string', 'max:255'],
            'medical_alerts' => ['sometimes', 'nullable', 'array'],
            'medical_alerts.*' => ['string', 'max:255'],
        ];
    }
}
