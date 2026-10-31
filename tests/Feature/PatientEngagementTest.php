<?php

namespace Tests\Feature;

use App\Communications\DeliveryException;
use App\Communications\SmtpProvider;
use App\Communications\TwilioProvider;
use App\Models\Appointment;
use App\Models\ClinicNotification;
use App\Models\NotificationDelivery;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppointmentWorkflow;
use App\Services\CommunicationConfigurationService;
use App\Services\PatientContactService;
use App\Services\PatientEngagementService;
use App\Services\TaskWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeNotificationProvider;
use Tests\TestCase;

class PatientEngagementTest extends TestCase
{
    use RefreshDatabase;

    private FakeNotificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeNotificationProvider;
        $this->app->instance(SmtpProvider::class, $this->provider);
        $this->app->instance(TwilioProvider::class, $this->provider);
        $this->travelTo(now()->startOfMinute());
    }

    public function test_booking_confirmation_reminder_timing_and_idempotency(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            $appointment = $this->book($patient, $actor);
            $this->assertDatabaseCount('clinic_notifications', 3);
            $this->assertDatabaseCount('notification_deliveries', 6);
            $this->assertCount(0, $this->provider->messages);
            app(PatientEngagementService::class)->appointmentChanged($appointment, 'appointment.confirmed', $actor->id);
            $this->assertDatabaseCount('clinic_notifications', 3);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->travel(24)->hours();
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(4, $this->provider->messages);
            $this->assertSame(2, NotificationDelivery::where('status', 'pending')->count());
            $this->assertDatabaseHas('audit_logs', ['action' => 'accepted', 'resource_type' => 'notification_deliveries']);
        });
    }

    public function test_reschedule_and_cancellation_suppress_obsolete_messages(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            $appointment = $this->book($patient, $actor);
            $appointment = app(AppointmentWorkflow::class)->update($appointment, ['appointment_date_time' => now()->addDays(4)], $actor);
            $this->assertSame(6, NotificationDelivery::where('status', 'suppressed')->count());
            $this->assertSame(1, ClinicNotification::where('event', 'appointment.rescheduled')->count());
            $appointment = app(AppointmentWorkflow::class)->update($appointment, ['status' => 'cancelled'], $actor);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->assertStringContainsString('cancelled', $this->provider->messages[0]['message']->body);
            $this->travel(5)->days();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->assertSame(12, NotificationDelivery::where('status', 'suppressed')->count());
        });
    }

    public function test_task_reminders_require_explicit_configuration_and_cancel_on_completion_or_deletion(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            Sanctum::actingAs($actor);
            $payload = ['patient_id' => $patient->id, 'assignee_id' => $actor->id, 'due_date' => now()->addDay()->toDateString(), 'description' => 'Private clinical details'];
            $this->postJson('/api/tasks', $payload)->assertCreated();
            $this->assertDatabaseCount('clinic_notifications', 0);
            $response = $this->postJson('/api/tasks', [...$payload, 'patient_reminder_at' => now()->addHour()->toIso8601String()])->assertCreated();
            $taskId = $response->json('data.task.id');
            $this->assertDatabaseCount('clinic_notifications', 1);
            $this->patchJson('/api/tasks/'.$taskId.'/status', ['status' => 'completed'])->assertOk();
            $this->assertSame(2, NotificationDelivery::where('status', 'suppressed')->count());
            $response = $this->postJson('/api/tasks', [...$payload, 'patient_reminder_at' => now()->addHour()->toIso8601String()])->assertCreated();
            $this->deleteJson('/api/tasks/'.$response->json('data.task.id'))->assertOk();
            $this->travel(2)->hours();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(0, $this->provider->messages);
            $this->assertDatabaseCount('clinic_notifications', 2);
            $this->postJson('/api/tasks', [...$payload, 'patient_id' => null, 'patient_reminder_at' => now()->addHour()->toIso8601String()])->assertUnprocessable();
        });
    }

    public function test_task_reminder_delivery_retries_and_does_not_expose_task_contents(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            app(TaskWorkflow::class)->create(['patient_id' => $patient->id, 'assignee_id' => $actor->id,
                'due_date' => now()->toDateString(), 'description' => 'Private clinical details',
                'patient_reminder_at' => now()->addMinute()], $actor);
            $this->provider->errors[] = new DeliveryException('provider_unavailable', retryable: true);
            $this->travel(1)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame(1, NotificationDelivery::where('status', 'retry')->count());
            $this->travel(1)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(3, $this->provider->messages);
            foreach ($this->provider->messages as $message) {
                $this->assertStringNotContainsString('Private clinical details', $message['message']->body);
            }
        });
    }

    public function test_contact_and_actor_revocation_are_rechecked_and_history_is_redacted(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            $appointment = $this->book($patient, $actor);
            $contact = $patient->id;
            $email = \App\Models\PatientContact::where('patient_id', $contact)->where('channel', 'email')->firstOrFail();
            app(PatientContactService::class)->revoke($patient, $email->id, $actor);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            Sanctum::actingAs($actor);
            $this->getJson('/api/patients/'.$patient->id.'/communications')->assertOk()
                ->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.variables')->assertJsonMissingPath('data.0.deliveries.0.address');
            $this->getJson('/api/appointments/'.$appointment->id.'/communications')->assertOk()->assertJsonCount(3, 'data');
            $actor->roles()->detach();
            $this->travel(24)->hours();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            $this->assertDatabaseHas('notification_deliveries', ['status' => 'suppressed', 'failure_reason' => 'trigger_no_longer_authorized']);
        });
    }

    public function test_foreign_history_and_sources_are_inaccessible(): void
    {
        [$actor, $patient, $appointment] = $this->within(function () {
            [$actor, $patient] = $this->setupClinic();

            return [$actor, $patient, $this->book($patient, $actor)];
        });
        $foreign = Tenant::factory()->create();
        $foreignActor = $this->within(fn () => User::factory()->create(), $foreign);
        Sanctum::actingAs($foreignActor);
        $this->withHeader('X-Tenant-ID', (string) $foreign->id)->getJson('/api/patients/'.$patient->id.'/communications')->assertNotFound();
        $this->withHeader('X-Tenant-ID', (string) $foreign->id)->getJson('/api/appointments/'.$appointment->id.'/communications')->assertNotFound();
        $this->within(function () use ($appointment) {
            $this->assertNull(Appointment::find($appointment->id));
            $this->assertSame(0, ClinicNotification::count());
        }, $foreign);
    }

    public function test_notification_outbox_rolls_back_with_source_and_configuration_validates_timing(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            try {
                DB::transaction(function () use ($actor, $patient) {
                    $this->book($patient, $actor);
                    throw new \RuntimeException('rollback');
                });
            } catch (\RuntimeException) {
            }
            $this->assertDatabaseCount('clinic_notifications', 0);
            $this->assertDatabaseCount('notification_deliveries', 0);
            $settings = \App\Models\CommunicationConfiguration::firstOrFail()->settings;
            $settings['events']['appointment.reminder']['minutes_before'] = [0, 60, 60];
            $this->expectException(ValidationException::class);
            app(CommunicationConfigurationService::class)->configure($settings, true);
        });
    }

    public function test_channel_configuration_contact_availability_and_unconfigured_events_are_respected(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            $configuration = \App\Models\CommunicationConfiguration::firstOrFail();
            $settings = $configuration->settings;
            $settings['events']['appointment.confirmed']['channels'] = ['sms'];
            unset($settings['events']['appointment.reminder'], $settings['events']['task.reminder']);
            app(CommunicationConfigurationService::class)->configure($settings, true);
            $this->book($patient, $actor);
            app(TaskWorkflow::class)->create(['patient_id' => $patient->id, 'assignee_id' => $actor->id,
                'due_date' => now()->toDateString(), 'description' => 'Follow-up', 'patient_reminder_at' => now()->addHour()], $actor);
            $this->assertDatabaseCount('clinic_notifications', 1);
            $this->assertDatabaseCount('notification_deliveries', 1);
            $this->assertSame('sms', NotificationDelivery::firstOrFail()->channel);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            $unreachable = Patient::factory()->create();
            $this->book($unreachable, $actor);
            $this->assertSame(1, ClinicNotification::where('selection_status', 'no_eligible_recipients')->count());
            $this->assertDatabaseCount('notification_deliveries', 1);
        });
    }

    public function test_task_reminder_normalizes_timezone_and_replaces_changed_timing(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            Sanctum::actingAs($actor);
            $when = now()->addHours(2)->setTimezone('America/Bogota');
            $response = $this->postJson('/api/tasks', ['patient_id' => $patient->id, 'assignee_id' => $actor->id,
                'due_date' => now()->toDateString(), 'description' => 'Follow-up', 'patient_reminder_at' => $when->toIso8601String()])->assertCreated();
            $taskId = $response->json('data.task.id');
            $this->assertTrue(NotificationDelivery::firstOrFail()->available_at->equalTo($when));
            $this->patchJson('/api/tasks/'.$taskId, ['patient_reminder_at' => $when->copy()->addHours(3)->toIso8601String()])->assertOk();
            $this->assertSame(2, NotificationDelivery::where('status', 'suppressed')->count());
            $this->travel(2)->hours();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(0, $this->provider->messages);
            $this->travel(3)->hours();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->patchJson('/api/tasks/'.$taskId.'/status', ['status' => 'completed', 'patient_reminder_at' => null])->assertUnprocessable();
        });
    }

    public function test_database_rejects_cross_tenant_notification_source_changes(): void
    {
        $foreign = Tenant::factory()->create();
        $foreignAppointment = $this->within(fn () => Appointment::factory()->create(), $foreign);
        $this->within(function () use ($foreignAppointment) {
            [$actor, $patient] = $this->setupClinic();
            $this->book($patient, $actor);
            $this->expectException(\Illuminate\Database\QueryException::class);
            DB::table('clinic_notifications')->where('id', ClinicNotification::firstOrFail()->id)
                ->update(['appointment_id' => $foreignAppointment->id]);
        });
    }

    public function test_late_appointment_reminders_and_cancelled_appointment_task_reminders_are_suppressed(): void
    {
        $this->within(function () {
            [$actor, $patient] = $this->setupClinic();
            $appointment = $this->book($patient, $actor);
            app(TaskWorkflow::class)->create(['appointment_id' => $appointment->id, 'assignee_id' => $actor->id,
                'due_date' => now()->toDateString(), 'description' => 'Follow-up', 'patient_reminder_at' => now()->addDays(3)], $actor);
            app(AppointmentWorkflow::class)->update($appointment, ['status' => 'cancelled'], $actor);
            $this->assertSame(2, NotificationDelivery::whereIn('notification_id', ClinicNotification::where('event', 'task.reminder')->select('id'))->where('status', 'suppressed')->count());
            $this->book($patient, $actor);
            $this->travel(4)->days();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages); // Only the cancellation is still relevant.
            $this->assertSame(0, NotificationDelivery::whereIn('status', ['pending', 'retry'])->count());
        });
    }

    private function setupClinic(): array
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Admin']);
        $actor->roles()->attach($role);
        $events = array_fill_keys(['appointment.confirmed', 'appointment.reminder', 'appointment.cancelled', 'appointment.rescheduled', 'task.reminder'], ['channels' => ['email', 'sms']]);
        $events['appointment.reminder']['minutes_before'] = [1440, 60];
        app(CommunicationConfigurationService::class)->configure(['channels' => [
            'email' => ['provider' => 'smtp', 'sender' => 'clinic@example.test', 'host' => 'smtp.example.test', 'port' => 465, 'username' => 'test', 'password' => 'secret'],
            'sms' => ['provider' => 'twilio', 'sender' => '+15555550100', 'account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'secret'],
        ], 'events' => $events], true);
        $patient = Patient::factory()->create();
        foreach (['email' => 'patient@example.test', 'sms' => '+15555550101'] as $channel => $address) {
            $contact = app(PatientContactService::class)->create($patient, ['channel' => $channel, 'address' => $address, 'role' => 'patient', 'authorized_events' => array_keys($events)], $actor);
            app(PatientContactService::class)->verify($patient, $contact->id, 'in_person', $actor);
        }

        return [$actor, $patient];
    }

    private function book(Patient $patient, User $actor): Appointment
    {
        $prototype = Appointment::factory()->make(['patient_id' => $patient->id]);

        return app(AppointmentWorkflow::class)->create([...$prototype->getAttributes(), 'appointment_date_time' => now()->addDays(2)], $actor);
    }

    private function within(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantContext::class)->run($tenant ?? Tenant::where('slug', Tenant::DEFAULT_SLUG)->firstOrFail(), $callback(...));
    }
}
