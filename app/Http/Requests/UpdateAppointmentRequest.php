<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends StoreAppointmentRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('appointment'));
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['patient_id', 'dentist_id', 'appointment_date_time', 'appointment_type', 'description'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        $rules['status'] = ['sometimes', 'required', Rule::in(Appointment::STATUSES)];
        $rules['cancellation_reason'] = ['sometimes', 'nullable', 'string', 'max:2000'];

        return $rules;
    }
}
