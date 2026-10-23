<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use App\Tenancy\TenantRules;
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
            'patient_id' => ['required', 'integer', TenantRules::exists('patient')],
            'dentist_id' => ['required', 'integer', TenantRules::exists('dentist')],
            'treatment_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('treatments')],
            'room_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('rooms')],
            'chair_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('chairs')],
            'appointment_date_time' => ['required', 'date'],
            'appointment_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['scheduled'])],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'cancellation_reason' => ['prohibited'],
        ];
    }
}
