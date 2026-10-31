<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflow
{
    public function create(array $data, ?User $actor = null): Task
    {
        return DB::transaction(fn () => $this->save(new Task, $data, $actor), 3);
    }

    public function update(Task $task, array $data, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            return $this->save(Task::query()->lockForUpdate()->findOrFail($task->id), $data, $actor);
        }, 3);
    }

    private function save(Task $task, array $data, ?User $actor): Task
    {
        $previousStatus = $task->status;
        $appointmentId = array_key_exists('appointment_id', $data) ? $data['appointment_id'] : $task->appointment_id;
        $appointment = $appointmentId !== null
            ? Appointment::query()->lockForUpdate()->findOrFail($appointmentId) : null;

        if (isset($data['appointment_id']) && ! array_key_exists('patient_id', $data)) {
            $data['patient_id'] = $appointment->patient_id;
        }

        if (! $task->exists) {
            $data['priority'] ??= 'normal';
            $data['status'] ??= 'pending';
        }

        if (isset($data['patient_reminder_at'])) {
            $data['patient_reminder_at'] = Carbon::parse($data['patient_reminder_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }
        $task->fill($data);

        if ($appointment !== null && $task->patient_id !== $appointment->patient_id) {
            throw ValidationException::withMessages([
                'patient_id' => ['The patient must match the linked appointment.'],
            ]);
        }

        if ($task->patient_id !== null) {
            Patient::query()->lockForUpdate()->findOrFail($task->patient_id);
        }

        User::forCurrentTenant()->lockForUpdate()->findOrFail($task->assignee_id);

        if ($task->status === 'completed') {
            if ($previousStatus !== 'completed') {
                $task->completed_at = now();
            }
        } else {
            $task->completed_at = null;
        }

        if ($task->patient_reminder_at !== null && $task->patient_id === null) {
            throw ValidationException::withMessages(['patient_reminder_at' => ['Patient reminders require a linked patient.']]);
        }
        if ($task->patient_reminder_at !== null && $actor === null) {
            throw ValidationException::withMessages(['patient_reminder_at' => ['Patient reminders require an authorized staff actor.']]);
        }
        $engagementChanged = ! $task->exists || $task->isDirty(['patient_id', 'appointment_id', 'patient_reminder_at', 'status']);
        if ($engagementChanged) {
            $task->communication_revision++;
        }
        $task->save();
        if ($engagementChanged && $actor !== null) {
            app(PatientEngagementService::class)->taskChanged($task, $actor->id);
        }

        return $task->refresh();
    }
}
