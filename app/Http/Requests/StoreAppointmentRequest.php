<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'patient_id' => 'patientId',
            'dentist_id' => 'dentistId',
            'treatment_id' => 'treatmentId',
            'room_id' => 'roomId',
            'chair_id' => 'chairId',
            'appointment_date_time' => 'startsAt',
            'appointment_type' => 'appointmentType',
            'duration_minutes' => 'durationMinutes',
            'status' => 'appointmentStatus',
            'cancellation_reason' => 'reason',
        ];

        foreach ($aliases as $canonical => $alias) {
            if (! $this->exists($canonical) && $this->exists($alias)) {
                $this->merge([$canonical => $this->input($alias)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['required', 'integer', 'exists:dentist,id'],
            'treatment_id' => ['sometimes', 'nullable', 'integer', 'exists:treatments,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'chair_id' => ['sometimes', 'nullable', 'integer', 'exists:chairs,id'],
            'appointment_date_time' => ['required', 'date'],
            'appointment_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['scheduled'])],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'cancellation_reason' => ['prohibited'],
        ];
    }
}
