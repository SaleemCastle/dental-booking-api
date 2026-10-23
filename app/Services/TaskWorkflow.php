<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflow
{
    public function create(array $data): Task
    {
        return DB::transaction(fn () => $this->save(new Task, $data), 3);
    }

    public function update(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            return $this->save(Task::query()->lockForUpdate()->findOrFail($task->id), $data);
        }, 3);
    }

    private function save(Task $task, array $data): Task
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

        $task->save();

        return $task->refresh();
    }
}
