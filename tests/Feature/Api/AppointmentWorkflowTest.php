<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Chair;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\AppointmentLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => Role::ADMIN]);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
    }

    #[DataProvider('overlapCases')]
    public function test_provider_conflicts_apply_to_create_and_reschedule(string $existingStart, int $existingDuration, string $start, int $duration, bool $overlaps): void
    {
        $existing = Appointment::factory()->create([
            'appointment_date_time' => $existingStart, 'duration_minutes' => $existingDuration, 'status' => 'scheduled',
        ]);
        $payload = $this->payload([
            'patient_id' => $existing->patient_id, 'dentist_id' => $existing->dentist_id,
            'appointment_date_time' => $start, 'duration_minutes' => $duration,
        ]);
        $response = $this->postJson('/api/appointments', $payload);

        if ($overlaps) {
            $response->assertUnprocessable()->assertJsonValidationErrors(['dentist_id', 'appointment_date_time']);
        } else {
            $response->assertCreated();
            Appointment::findOrFail($response->json('data.appointment.id'))->delete();
        }

        $target = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-10-20 14:00:00']);
        $original = $target->refresh()->getAttributes();
        $response = $this->patchJson("/api/appointments/{$target->id}", [
            'dentist_id' => $existing->dentist_id, 'appointment_date_time' => $start, 'duration_minutes' => $duration,
        ]);

        if ($overlaps) {
            $response->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
            $this->assertSame($original, $target->refresh()->getAttributes());
        } else {
            $response->assertOk()->assertJsonPath('data.appointment.dentist_id', $existing->dentist_id);
        }
    }

    public static function overlapCases(): array
    {
        return [
            'same interval' => ['2026-10-20 09:00:00', 60, '2026-10-20 09:00:00', 60, true],
            'contained' => ['2026-10-20 09:00:00', 60, '2026-10-20 09:15:00', 15, true],
            'contains' => ['2026-10-20 09:00:00', 60, '2026-10-20 08:30:00', 120, true],
            'left edge' => ['2026-10-20 09:00:00', 60, '2026-10-20 08:59:00', 2, true],
            'right edge' => ['2026-10-20 09:00:00', 60, '2026-10-20 09:59:00', 2, true],
            'adjacent before' => ['2026-10-20 09:00:00', 60, '2026-10-20 08:30:00', 30, false],
            'adjacent after' => ['2026-10-20 09:00:00', 60, '2026-10-20 10:00:00', 30, false],
            'previous day spillover' => ['2026-10-20 23:45:00', 60, '2026-10-21 00:15:00', 30, true],
            'next day spillover' => ['2026-10-21 00:15:00', 60, '2026-10-20 23:45:00', 60, true],
            'different day' => ['2026-10-20 09:00:00', 60, '2026-10-21 09:00:00', 30, false],
            'timezone equivalent' => ['2026-10-20 09:00:00', 60, '2026-10-20T04:30:00-05:00', 30, true],
            'maximum duration spillover' => ['2026-10-20 00:01:00', 1440, '2026-10-21 00:00:00', 30, true],
        ];
    }

    #[DataProvider('locationConflicts')]
    public function test_location_conflicts_apply_across_providers(string $resource, string $method): void
    {
        $chair = Chair::factory()->create();
        $otherChair = Chair::factory()->create(['room_id' => $chair->room_id]);
        Appointment::factory()->create([
            'room_id' => $chair->room_id, 'chair_id' => $chair->id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-10-20 09:00:00', 'duration_minutes' => 60,
        ]);
        $target = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-10-20 13:00:00']);
        $payload = $this->payload([
            'dentist_id' => $target->dentist_id, 'patient_id' => $target->patient_id,
            'room_id' => $chair->room_id, 'chair_id' => $resource === 'chair' ? $chair->id : $otherChair->id,
            'appointment_date_time' => '2026-10-20 09:30:00',
        ]);

        $this->json($method, $method === 'POST' ? '/api/appointments' : "/api/appointments/{$target->id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($resource.'_id');
        $this->assertSame('2026-10-20 13:00:00', $target->refresh()->appointment_date_time->format('Y-m-d H:i:s'));
    }

    public static function locationConflicts(): array
    {
        return [
            'create chair' => ['chair', 'POST'], 'reschedule chair' => ['chair', 'PATCH'],
            'create room with different chair' => ['room', 'POST'], 'reschedule room with different chair' => ['room', 'PATCH'],
        ];
    }

    public function test_location_changes_without_time_changes_are_validated(): void
    {
        $chair = Chair::factory()->create();
        Appointment::factory()->create([
            'room_id' => $chair->room_id, 'chair_id' => $chair->id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-10-20 09:00:00',
        ]);
        $target = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-10-20 09:00:00']);

        $this->patchJson("/api/appointments/{$target->id}", ['chairId' => $chair->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['chair_id', 'room_id']);
        $this->assertNull($target->refresh()->chair_id);
    }

    #[DataProvider('existingStatuses')]
    public function test_only_active_appointments_reserve_resources(string $status, bool $blocks): void
    {
        $chair = Chair::factory()->create();
        $existing = Appointment::factory()->create([
            'status' => $status, 'room_id' => $chair->room_id, 'chair_id' => $chair->id,
            'appointment_date_time' => '2026-10-20 09:00:00', 'duration_minutes' => 60,
        ]);

        $response = $this->postJson('/api/appointments', $this->payload([
            'dentist_id' => $existing->dentist_id, 'chair_id' => $chair->id,
        ]));
        $response->assertStatus($blocks ? 422 : 201);
    }

    public static function existingStatuses(): array
    {
        return [
            ['scheduled', true], ['checked_in', true], ['in_progress', true],
            ['completed', false], ['cancelled', false], ['no_show', false],
        ];
    }

    #[DataProvider('statusTransitions')]
    public function test_status_transition_matrix_is_enforced(string $from, string $to, bool $allowed): void
    {
        $appointment = Appointment::factory()->create(['status' => $from]);
        $response = $this->patchJson("/api/appointments/{$appointment->id}", ['status' => $to]);

        if ($allowed) {
            $response->assertOk()->assertJsonPath('data.appointment.status', $to);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertSame($from, $appointment->refresh()->status);
        }
    }

    public static function statusTransitions(): array
    {
        $allowed = [
            'scheduled' => ['scheduled', 'checked_in', 'cancelled', 'no_show'],
            'checked_in' => ['checked_in', 'in_progress', 'cancelled'],
            'in_progress' => ['in_progress', 'completed', 'cancelled'],
            'completed' => ['completed'], 'cancelled' => ['cancelled'], 'no_show' => ['no_show'],
        ];
        $cases = [];

        foreach ($allowed as $from => $targets) {
            foreach (array_keys($allowed) as $to) {
                $cases[$from.' to '.$to] = [$from, $to, in_array($to, $targets, true)];
            }
        }

        return $cases;
    }

    public function test_dedicated_actions_enforce_order_and_preserve_timestamps_on_retries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);
        $this->postJson("/api/appointments/{$appointment->id}/complete")->assertUnprocessable();
        $response = $this->postJson("/api/appointments/{$appointment->id}/check-in")->assertOk();
        $checkedInAt = $response->json('data.appointment.checked_in_at');
        $this->travel(5)->minutes();
        $this->postJson("/api/appointments/{$appointment->id}/check-in")
            ->assertOk()->assertJsonPath('data.appointment.checked_in_at', $checkedInAt);
        $this->postJson("/api/appointments/{$appointment->id}/complete")->assertUnprocessable();
        $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])->assertOk();
        $response = $this->postJson("/api/appointments/{$appointment->id}/complete")->assertOk();
        $completedAt = $response->json('data.appointment.completed_at');
        $this->travel(5)->minutes();
        $this->postJson("/api/appointments/{$appointment->id}/complete")
            ->assertOk()->assertJsonPath('data.appointment.completed_at', $completedAt);
        $this->postJson("/api/appointments/{$appointment->id}/check-in")->assertUnprocessable();
        $this->postJson("/api/appointments/{$appointment->id}/cancel")->assertUnprocessable();
    }

    public function test_cancellation_reason_is_retained_and_the_slot_is_released(): void
    {
        $appointment = Appointment::factory()->create([
            'status' => 'scheduled', 'appointment_date_time' => '2026-10-20 09:00:00',
        ]);
        $response = $this->postJson("/api/appointments/{$appointment->id}/cancel", ['reason' => 'Patient requested cancellation.'])
            ->assertOk()->assertJsonPath('data.appointment.cancellation_reason', 'Patient requested cancellation.');
        $cancelledAt = $response->json('data.appointment.cancelled_at');
        $this->travel(5)->minutes();
        $this->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()->assertJsonPath('data.appointment.cancelled_at', $cancelledAt)
            ->assertJsonPath('data.appointment.cancellation_reason', 'Patient requested cancellation.');
        $this->postJson('/api/appointments', $this->payload(['dentist_id' => $appointment->dentist_id]))->assertCreated();
    }

    public function test_generic_update_supports_cancellation_and_validates_reasons(): void
    {
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);
        $this->patchJson("/api/appointments/{$appointment->id}", ['cancellation_reason' => 'Not cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason');
        $this->postJson("/api/appointments/{$appointment->id}/cancel", ['reason' => str_repeat('a', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason');
        $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'cancelled', 'cancellation_reason' => 'Patient unavailable'])
            ->assertOk()->assertJsonPath('data.appointment.cancellation_reason', 'Patient unavailable');
        $this->assertNotNull($appointment->refresh()->cancelled_at);
    }

    public function test_terminal_appointments_cannot_be_rescheduled(): void
    {
        foreach (['completed', 'cancelled', 'no_show'] as $status) {
            $appointment = Appointment::factory()->create(['status' => $status]);
            $this->patchJson("/api/appointments/{$appointment->id}", ['startsAt' => '2026-10-21T10:00:00Z'])
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
    }

    public function test_canonical_values_take_precedence_over_aliases_including_explicit_null(): void
    {
        $chair = Chair::factory()->create();
        $payload = $this->payload([
            'chair_id' => null, 'chairId' => $chair->id,
            'appointment_date_time' => '2026-10-20T09:00:00Z', 'startsAt' => 'invalid',
        ]);
        $response = $this->postJson('/api/appointments', $payload)->assertCreated()
            ->assertJsonPath('data.appointment.chair_id', null)
            ->assertJsonPath('data.appointment.startsAt', '2026-10-20T09:00:00.000000Z');
        $identifier = $response->json('data.appointment.id');
        $this->postJson("/api/appointments/{$identifier}/cancel", [
            'cancellation_reason' => null, 'reason' => str_repeat('a', 2001),
        ])->assertOk()->assertJsonPath('data.appointment.cancellation_reason', null);
    }

    public function test_partial_alias_updates_preserve_omitted_fields_and_ignore_their_own_slot(): void
    {
        $appointment = Appointment::factory()->create([
            'status' => 'scheduled', 'appointment_date_time' => '2026-10-20 09:00:00', 'duration_minutes' => 60,
        ]);
        $this->patchJson("/api/appointments/{$appointment->id}", ['description' => 'Updated note'])
            ->assertOk()->assertJsonPath('data.appointment.patient_id', $appointment->patient_id)
            ->assertJsonPath('data.appointment.treatment_id', $appointment->treatment_id)
            ->assertJsonPath('data.appointment.duration_minutes', 60);
        $this->patchJson("/api/appointments/{$appointment->id}", ['startsAt' => '2026-10-20T09:15:00Z'])
            ->assertOk()->assertJsonPath('data.appointment.startsAt', '2026-10-20T09:15:00.000000Z')
            ->assertJsonPath('data.appointment.endsAt', '2026-10-20T10:15:00.000000Z');
    }

    public function test_duration_increases_and_treatment_defaults_are_checked_for_conflicts(): void
    {
        $existing = Appointment::factory()->create([
            'status' => 'scheduled', 'appointment_date_time' => '2026-10-20 10:00:00',
        ]);
        $target = Appointment::factory()->create([
            'dentist_id' => $existing->dentist_id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-10-20 09:00:00', 'duration_minutes' => 30,
        ]);
        $this->patchJson("/api/appointments/{$target->id}", ['durationMinutes' => 90])
            ->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $treatment = Treatment::factory()->create(['duration_minutes' => 90]);
        $payload = $this->payload(['dentist_id' => $existing->dentist_id, 'treatment_id' => $treatment->id]);
        unset($payload['duration_minutes']);
        $this->postJson('/api/appointments', $payload)->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $this->patchJson("/api/appointments/{$target->id}", ['treatmentId' => $treatment->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
    }

    public function test_creation_aliases_return_canonical_ids_and_display_fields(): void
    {
        $patient = Patient::factory()->create(['firstName' => 'Jane', 'lastName' => 'Doe']);
        $dentist = Dentist::factory()->create(['first_name' => 'Alex', 'last_name' => 'Rivera']);
        $treatment = Treatment::factory()->create(['name' => 'Cleaning', 'duration_minutes' => 45]);
        $chair = Chair::factory()->create(['name' => 'Chair A']);
        $response = $this->postJson('/api/appointments', [
            'patientId' => (string) $patient->id, 'dentistId' => (string) $dentist->id,
            'treatmentId' => (string) $treatment->id, 'chairId' => (string) $chair->id,
            'startsAt' => '2026-10-20T09:00:00-05:00', 'appointmentType' => 'Cleaning', 'description' => 'Routine cleaning',
        ])->assertCreated();

        foreach (['patient' => $patient->id, 'dentist' => $dentist->id, 'treatment' => $treatment->id, 'room' => $chair->room_id, 'chair' => $chair->id] as $name => $identifier) {
            $response->assertJsonPath('data.appointment.'.$name.'_id', $identifier)
                ->assertJsonPath('data.appointment.'.$name.'Id', $identifier);
        }

        $response->assertJsonPath('data.appointment.patientName', 'Jane Doe')
            ->assertJsonPath('data.appointment.dentistName', 'Alex Rivera')
            ->assertJsonPath('data.appointment.treatmentName', 'Cleaning')
            ->assertJsonPath('data.appointment.roomName', $chair->room->name)
            ->assertJsonPath('data.appointment.chairName', 'Chair A')
            ->assertJsonPath('data.appointment.status', 'scheduled')
            ->assertJsonPath('data.appointment.duration_minutes', 45)
            ->assertJsonPath('data.appointment.startsAt', '2026-10-20T14:00:00.000000Z')
            ->assertJsonPath('data.appointment.endsAt', '2026-10-20T14:45:00.000000Z');

        $identifier = $response->json('data.appointment.id');
        $this->getJson("/api/appointments/{$identifier}")->assertOk()->assertJsonPath('data.appointment.chair_id', $chair->id);
        $this->getJson('/api/appointments?date=2026-10-20')->assertOk()->assertJsonPath('data.appointments.0.id', $identifier);
        $this->getJson('/api/appointments?date=2026-10-21')->assertOk()->assertJsonCount(0, 'data.appointments');
    }

    public function test_invalid_references_duration_and_initial_status_are_rejected(): void
    {
        $payload = $this->payload();
        foreach ([
            ['patient_id', 999999], ['dentist_id', 999999], ['treatment_id', 999999], ['room_id', 999999],
            ['chair_id', 999999], ['duration_minutes', null], ['duration_minutes', 0], ['duration_minutes', 1441],
            ['status', 'completed'], ['status', 'cancelled'], ['appointment_date_time', 'invalid'],
        ] as [$field, $value]) {
            $this->postJson('/api/appointments', array_replace($payload, [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('appointment', 0);
    }

    public function test_new_bookings_reject_archived_patients_and_inactive_treatments_but_history_remains_editable(): void
    {
        $patient = Patient::factory()->create();
        $patient->archive();
        $treatment = Treatment::factory()->create(['active' => false]);
        $this->postJson('/api/appointments', $this->payload(['patient_id' => $patient->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->postJson('/api/appointments', $this->payload(['treatment_id' => $treatment->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('treatment_id');
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id, 'treatment_id' => $treatment->id, 'status' => 'scheduled',
        ]);
        $this->patchJson("/api/appointments/{$appointment->id}", ['description' => 'Historical note'])
            ->assertOk()->assertJsonPath('data.appointment.treatment_id', $treatment->id);
        $this->postJson("/api/appointments/{$appointment->id}/cancel")->assertOk();
    }

    public function test_chairs_must_match_the_room_and_can_be_reassigned_or_cleared(): void
    {
        $chair = Chair::factory()->create();
        $other = Chair::factory()->create();
        $this->postJson('/api/appointments', $this->payload(['chair_id' => $chair->id, 'room_id' => $other->room_id]))
            ->assertUnprocessable()->assertJsonValidationErrors('chair_id');
        $appointment = Appointment::factory()->create(['status' => 'scheduled', 'chair_id' => $chair->id, 'room_id' => $chair->room_id]);
        $this->patchJson("/api/appointments/{$appointment->id}", ['chairId' => $other->id])
            ->assertOk()->assertJsonPath('data.appointment.room_id', $other->room_id);
        $this->patchJson("/api/appointments/{$appointment->id}", ['room_id' => $chair->room_id])
            ->assertUnprocessable()->assertJsonValidationErrors('chair_id');
        $this->patchJson("/api/appointments/{$appointment->id}", ['chair_id' => null, 'room_id' => null])
            ->assertOk()->assertJsonPath('data.appointment.chair_id', null)->assertJsonPath('data.appointment.room_id', null);
    }

    public function test_locations_are_seeded_idempotently_and_exposed_as_selector_options(): void
    {
        $this->seed(AppointmentLocationSeeder::class);
        $this->seed(AppointmentLocationSeeder::class);
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('chairs', 2);
        $room = Room::where('name', 'Room 1')->firstOrFail();
        $this->getJson('/api/appointment-locations')->assertOk()
            ->assertJsonCount(2, 'data.rooms')
            ->assertJsonPath('data.rooms.0.id', $room->id)
            ->assertJsonPath('data.rooms.0.chairs.0.room_id', $room->id)
            ->assertJsonPath('data.rooms.0.chairs.0.name', 'Chair 1');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'patient_id' => Patient::factory()->create()->id,
            'dentist_id' => Dentist::factory()->create()->id,
            'appointment_date_time' => '2026-10-20 09:00:00',
            'appointment_type' => 'Cleaning', 'description' => 'Routine cleaning', 'duration_minutes' => 30,
        ], $overrides);
    }
}
