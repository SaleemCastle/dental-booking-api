<?php

namespace App\Services;

use App\Communications\DeliveryException;
use App\Models\Appointment;
use App\Models\ClinicNotification;
use App\Models\CommunicationConfiguration;
use App\Models\NotificationDelivery;
use App\Models\Patient;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Called inside the source workflow transaction, with its source row locked. */
final class PatientEngagementService
{
    public function appointmentChanged(Appointment $appointment, ?string $event, int $actorId): void
    {
        $this->suppress('appointment_id', $appointment->id, $actorId, keepRevision: $appointment->communication_revision);
        if ($event !== null) {
            $this->enqueue($appointment, $event, $actorId, now('UTC'));
        }
        if ($appointment->status !== 'scheduled') {
            return;
        }
        $configuration = CommunicationConfiguration::where('enabled', true)->first();
        foreach ($configuration?->settings['events']['appointment.reminder']['minutes_before'] ?? [] as $minutes) {
            $due = $appointment->appointment_date_time->copy()->utc()->subMinutes($minutes);
            // Never send a burst of already missed reminders when booking near the appointment.
            if ($due->isFuture()) {
                $this->enqueue($appointment, 'appointment.reminder', $actorId, $due, (string) $minutes);
            }
        }
    }

    public function taskChanged(Task $task, int $actorId): void
    {
        $this->suppress('task_id', $task->id, $actorId, keepRevision: $task->communication_revision);
        if ($task->patient_reminder_at && $task->patient_reminder_at->isFuture()
            && in_array($task->status, Task::OPEN_STATUSES, true)) {
            $this->enqueue($task, 'task.reminder', $actorId, $task->patient_reminder_at->copy()->utc());
        }
    }

    public function suppress(string $column, int $id, ?int $actorId, ?int $keepRevision = null): void
    {
        if (! in_array($column, ['appointment_id', 'task_id'], true)) {
            throw new \InvalidArgumentException('Unsupported communication source.');
        }
        $ids = ClinicNotification::where($column, $id)->whereNull('invoice_id')
            ->when($keepRevision !== null, fn ($q) => $q->where('source_revision', '<>', $keepRevision))
            ->when($keepRevision !== null && $column === 'appointment_id', fn ($q) => $q->whereNull('task_id'))->pluck('id');
        NotificationDelivery::whereIn('notification_id', $ids)->whereIn('status', ['pending', 'retry'])
            ->lockForUpdate()->get()->each(function ($delivery) use ($actorId) {
                $delivery->update(['status' => 'suppressed', 'failure_reason' => 'source_superseded']);
                app(AuditLogService::class)->record('suppressed.source_superseded', 'notification_deliveries', $delivery->id, $actorId);
            });
    }

    private function enqueue(Appointment|Task $source, string $event, int $actorId, $due, string $suffix = ''): void
    {
        $configuration = CommunicationConfiguration::where('enabled', true)->lockForUpdate()->first();
        if (! $configuration || ! isset($configuration->settings['events'][$event])) {
            return;
        }
        $patient = Patient::query()->active()->find($source->patient_id);
        if (! $patient) {
            return;
        }
        $appointment = $source instanceof Appointment;
        $key = $event.':'.$source->id.':'.$source->communication_revision.':'.$suffix;
        if (ClinicNotification::where('event_key', $key)->exists()) {
            return;
        }
        $notification = ClinicNotification::create([
            'configuration_id' => $configuration->id, 'configuration_revision' => $configuration->revision,
            'event' => $event, 'event_key' => $key, 'patient_id' => $patient->id,
            'appointment_id' => $appointment ? $source->id : $source->appointment_id,
            'task_id' => $appointment ? null : $source->id, 'source_revision' => $source->communication_revision,
            'actor_id' => $actorId, 'user_triggered' => true, 'trigger' => $source->wasRecentlyCreated ? 'staff_create' : 'staff_update',
            'variables' => ['patient_name' => trim($patient->firstName.' '.$patient->lastName),
                'appointment_time' => $appointment ? $source->appointment_date_time->toIso8601String() : '',
                'reminder_time' => $appointment ? '' : $source->patient_reminder_at->toIso8601String()],
            'selection_status' => 'pending',
        ]);
        $recipients = app(NotificationRecipients::class)->select($notification, $configuration->settings);
        foreach ($recipients as $recipient) {
            $notification->deliveries()->create([
                ...$recipient, 'recipient_hash' => hash_hmac('sha256', strtolower($recipient['address']), config('app.key')),
                'operation_key' => (string) Str::uuid(), 'available_at' => $due,
            ]);
        }
        $notification->update(['selection_status' => $recipients ? 'queued' : 'no_eligible_recipients']);
        app(AuditLogService::class)->record($notification->selection_status, 'clinic_notifications', $notification->id, $actorId);
    }

    public function validateSource(ClinicNotification $notification): void
    {
        $source = $notification->task_id !== null ? Task::find($notification->task_id) : Appointment::find($notification->appointment_id);
        if (! $source || $source->patient_id !== $notification->patient_id
            || $source->communication_revision !== $notification->source_revision) {
            throw new DeliveryException('source_superseded');
        }
        $actor = User::findOrFail($notification->actor_id);
        Gate::forUser($actor)->authorize($notification->trigger === 'staff_create' ? 'create' : 'update',
            $notification->trigger === 'staff_create' ? $source::class : $source);
        if ($source instanceof Appointment) {
            $valid = $notification->event === 'appointment.cancelled' ? $source->status === 'cancelled'
                : ($notification->event === 'appointment.rescheduled' ? in_array($source->status, Appointment::ACTIVE_STATUSES, true) : $source->status === 'scheduled')
                    && $source->appointment_date_time->isFuture();
            $valid = $valid && $source->appointment_date_time->toIso8601String() === $notification->variables['appointment_time'];
        } else {
            $valid = in_array($source->status, Task::OPEN_STATUSES, true) && $source->patient_reminder_at !== null
                && $source->patient_reminder_at->toIso8601String() === $notification->variables['reminder_time']
                && $source->appointment_id === $notification->appointment_id;
            if ($source->appointment_id !== null) {
                $valid = $valid && Appointment::whereKey($source->appointment_id)->where('patient_id', $source->patient_id)
                    ->whereNotIn('status', ['cancelled', 'no_show'])->exists();
            }
        }
        if (! $valid) {
            throw new DeliveryException('source_superseded');
        }
    }
}
