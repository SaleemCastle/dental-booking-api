<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $patientName = $this->whenLoaded('patient', fn () => trim($this->patient->firstName.' '.$this->patient->lastName));
        $dentistName = $this->whenLoaded('dentist', fn () => trim($this->dentist->first_name.' '.$this->dentist->last_name));
        $treatmentName = $this->whenLoaded('treatment', fn () => $this->treatment?->name);
        $paymentStatus = $this->relationLoaded('invoice')
            ? ($this->invoice?->status ?? 'unpaid')
            : 'unpaid';

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'dentist_id' => $this->dentist_id,
            'treatment_id' => $this->treatment_id,
            'room_id' => $this->room_id,
            'chair_id' => $this->chair_id,
            'appointment_date_time' => $this->appointment_date_time->toISOString(),
            'appointment_type' => $this->appointment_type,
            'description' => $this->description,
            'status' => $this->status,
            'duration_minutes' => $this->duration_minutes,
            'patientName' => $patientName,
            'dentistName' => $dentistName,
            'treatmentName' => $treatmentName,
            'roomName' => $this->whenLoaded('room', fn () => $this->room?->name),
            'chairName' => $this->whenLoaded('chair', fn () => $this->chair?->name),
            'patientId' => $this->patient_id,
            'dentistId' => $this->dentist_id,
            'treatmentId' => $this->treatment_id,
            'roomId' => $this->room_id,
            'chairId' => $this->chair_id,
            'startsAt' => $this->appointment_date_time->toISOString(),
            'endsAt' => $this->appointment_date_time->copy()->addMinutes($this->duration_minutes)->toISOString(),
            'duration' => $this->duration_minutes,
            'appointmentStatus' => $this->status,
            'paymentStatus' => $paymentStatus,
            'checked_in_at' => $this->checked_in_at,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'dentist' => new DentistResource($this->whenLoaded('dentist')),
            'treatment' => new TreatmentResource($this->whenLoaded('treatment')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
