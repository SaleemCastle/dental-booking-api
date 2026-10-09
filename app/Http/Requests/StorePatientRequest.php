<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'appointments' => ['required', 'string', 'max:255'],
            'sex' => ['required', 'string', 'max:255'],
            'streetAddress' => ['required', 'string', 'max:255'],
            'town' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'notes' => ['required', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:255'],
            'medications' => ['nullable', 'array'],
            'medications.*' => ['string', 'max:255'],
            'medical_alerts' => ['nullable', 'array'],
            'medical_alerts.*' => ['string', 'max:255'],
        ];
    }
}
