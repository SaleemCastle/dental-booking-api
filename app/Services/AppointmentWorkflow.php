<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Chair;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Treatment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentWorkflow
{
    private const TRANSITIONS = [
        'scheduled' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    private const SCHEDULE_FIELDS = [
        'patient_id', 'dentist_id', 'treatment_id', 'room_id', 'chair_id',
        'appointment_date_time', 'duration_minutes',
    ];

    public function create(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = 'scheduled';

            return $this->save(new Appointment, $data);
        }, 3);
    }

    public function update(Appointment $appointment, array $data): Appointment
    {
        return DB::transaction(function () use ($appointment, $data) {
            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            return $this->save($current, $data);
        }, 3);
    }

    private function save(Appointment $appointment, array $data): Appointment
    {
        $previousStatus = $appointment->status;
        $status = $data['status'] ?? $previousStatus;

        if ($appointment->exists && $status !== $previousStatus && ! in_array($status, self::TRANSITIONS[$previousStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from {$previousStatus} to {$status}."],
            ]);
        }

        if (array_key_exists('cancellation_reason', $data) && $status !== 'cancelled') {
            throw ValidationException::withMessages([
                'cancellation_reason' => ['A cancellation reason is only allowed for cancelled appointments.'],
            ]);
        }

        if (! $appointment->exists || (isset($data['patient_id']) && (int) $data['patient_id'] !== $appointment->patient_id)) {
            $patient = Patient::query()->lockForUpdate()->findOrFail($data['patient_id']);

            if ($patient->archived_at !== null) {
                throw ValidationException::withMessages(['patient_id' => ['Archived patients cannot be selected for new bookings.']]);
            }
        }

        $treatmentChanged = isset($data['treatment_id']) && (int) $data['treatment_id'] !== $appointment->treatment_id;

        if ($treatmentChanged) {
            $treatment = Treatment::query()->lockForUpdate()->findOrFail($data['treatment_id']);

            if (! $treatment->active) {
                throw ValidationException::withMessages(['treatment_id' => ['Inactive treatments cannot be selected for new bookings.']]);
            }

            $data['duration_minutes'] ??= $treatment->duration_minutes;
        }

        if (! $appointment->exists) {
            $data['duration_minutes'] ??= 30;
        }

        if (isset($data['appointment_date_time'])) {
            $data['appointment_date_time'] = Carbon::parse($data['appointment_date_time'])
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        }

        $chairId = array_key_exists('chair_id', $data) ? $data['chair_id'] : $appointment->chair_id;

        if ($chairId !== null) {
            $chair = Chair::findOrFail($chairId);

            if (! array_key_exists('room_id', $data) && (! $appointment->exists || array_key_exists('chair_id', $data))) {
                $data['room_id'] = $chair->room_id;
            }

            $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : $appointment->room_id;

            if ((int) $roomId !== $chair->room_id) {
                throw ValidationException::withMessages(['chair_id' => ['The chair must belong to the selected room.']]);
            }
        }

        $appointment->fill($data);

        if ($appointment->exists && $appointment->isDirty('patient_id') && $appointment->tasks()->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => ['An appointment with tasks cannot be reassigned to a different patient.'],
            ]);
        }

        if ($appointment->exists && $appointment->isDirty(['patient_id', 'dentist_id']) && $appointment->clinicalNotes()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => ['An appointment with clinical notes cannot be reassigned to a different patient or provider.'],
            ]);
        }

        if ($appointment->exists && ! in_array($previousStatus, Appointment::ACTIVE_STATUSES, true) && $appointment->isDirty(self::SCHEDULE_FIELDS)) {
            throw ValidationException::withMessages(['status' => ['Terminal appointments cannot be rescheduled.']]);
        }

        Dentist::query()->lockForUpdate()->findOrFail($appointment->dentist_id);

        if ($appointment->room_id !== null) {
            Room::query()->lockForUpdate()->findOrFail($appointment->room_id);
        }

        if ($appointment->chair_id !== null) {
            Chair::query()->lockForUpdate()->findOrFail($appointment->chair_id);
        }

        if (in_array($status, Appointment::ACTIVE_STATUSES, true)) {
            $this->ensureAvailable($appointment);
        }

        if ($status !== $previousStatus) {
            $timestamp = match ($status) {
                'checked_in' => 'checked_in_at',
                'completed' => 'completed_at',
                'cancelled' => 'cancelled_at',
                default => null,
            };

            if ($timestamp !== null) {
                $appointment->{$timestamp} = now();
            }
        }

        $appointment->save();

        return $appointment->refresh();
    }

    private function ensureAvailable(Appointment $appointment): void
    {
        $start = $appointment->appointment_date_time;
        $end = $start->copy()->addMinutes($appointment->duration_minutes);

        $candidates = Appointment::query()
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->where('appointment_date_time', '>=', $start->copy()->subDay())
            ->where('appointment_date_time', '<', $end)
            ->when($appointment->exists, fn ($query) => $query->whereKeyNot($appointment->id))
            ->where(function ($query) use ($appointment) {
                $query->where('dentist_id', $appointment->dentist_id)
                    ->when($appointment->room_id !== null, fn ($query) => $query->orWhere('room_id', $appointment->room_id))
                    ->when($appointment->chair_id !== null, fn ($query) => $query->orWhere('chair_id', $appointment->chair_id));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $errors = [];

        foreach ($candidates as $existing) {
            if ($existing->appointment_date_time->copy()->addMinutes($existing->duration_minutes)->lte($start)) {
                continue;
            }

            foreach (['dentist_id' => 'provider', 'room_id' => 'room', 'chair_id' => 'chair'] as $field => $label) {
                if ($appointment->{$field} !== null && $appointment->{$field} === $existing->{$field}) {
                    $errors[$field] = ["The {$label} already has an appointment during this time."];
                }
            }
        }

        if ($errors !== []) {
            $errors['appointment_date_time'] = ['The requested time conflicts with an existing appointment.'];

            throw ValidationException::withMessages($errors);
        }
    }
}
