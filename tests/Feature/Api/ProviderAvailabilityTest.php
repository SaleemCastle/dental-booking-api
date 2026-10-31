<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chair;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProviderAvailability;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Dentist $dentist;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => Role::ADMIN]);
        $this->actor->roles()->attach($role);
        Sanctum::actingAs($this->actor);
        $this->dentist = Dentist::factory()->create();
    }

    public function test_configuration_round_trips_and_audits_only_changed_writes(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.availability.configured', false)
            ->assertJsonPath('data.availability.timezone', 'UTC');
        $schedule = $this->schedule();
        $this->putJson($this->url(), $schedule)->assertOk()
            ->assertJsonPath('data.availability.configured', true)
            ->assertJsonPath('data.availability.weekly_hours', $schedule['weekly_hours']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.availability.exceptions', []);
        $this->putJson($this->url(), $schedule)->assertOk();
        $reordered = $schedule;
        $reordered['weekly_hours'][0] = array_reverse($schedule['weekly_hours'][0], true);
        $reordered['breaks'][0] = array_reverse($schedule['breaks'][0], true);
        $this->putJson($this->url(), $reordered)->assertOk();
        $schedule['exceptions'] = [['date' => '2026-11-03', 'working_hours' => [], 'breaks' => []]];
        $this->putJson($this->url(), $schedule)->assertOk();
        $logs = AuditLog::where('resource_type', 'provider_availabilities')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
        $this->assertSame([$this->actor->id, $this->actor->id], $logs->pluck('actor_id')->all());
        $this->assertSame((string) ProviderAvailability::sole()->id, $logs[0]->resource_id);
        $this->assertArrayNotHasKey('weekly_hours', $logs[0]->request_metadata);
    }

    #[DataProvider('bookingCases')]
    public function test_hours_breaks_and_boundaries_apply_to_creation_and_rescheduling(string $start, int $duration, bool $allowed): void
    {
        $this->putJson($this->url(), $this->schedule())->assertOk();
        $response = $this->postJson('/api/appointments', $this->payload($start, $duration));
        if ($allowed) {
            $response->assertCreated();
            Appointment::findOrFail($response->json('data.appointment.id'))->delete();
        } else {
            $response->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
                ->assertJsonValidationErrors(['dentist_id', 'appointment_date_time']);
        }

        $target = Appointment::factory()->create([
            'dentist_id' => $this->dentist->id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-11-03 10:00:00', 'duration_minutes' => 30,
        ]);
        $before = $target->refresh()->getAttributes();
        $response = $this->patchJson("/api/appointments/{$target->id}", [
            'startsAt' => $start, 'durationMinutes' => $duration,
        ]);
        if ($allowed) {
            $response->assertOk();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('appointment_date_time');
            $this->assertSame($before, $target->refresh()->getAttributes());
        }
    }

    public static function bookingCases(): array
    {
        return [
            'opens at nine' => ['2026-11-03 09:00:00', 30, true],
            'ends at closing' => ['2026-11-03 16:30:00', 30, true],
            'starts before opening' => ['2026-11-03 08:59:00', 30, false],
            'ends after closing' => ['2026-11-03 16:45:00', 30, false],
            'closed weekday' => ['2026-11-04 09:00:00', 30, false],
            'during lunch' => ['2026-11-03 12:15:00', 30, false],
            'spans lunch' => ['2026-11-03 11:30:00', 120, false],
            'ends at break start' => ['2026-11-03 11:30:00', 30, true],
            'starts at break end' => ['2026-11-03 13:00:00', 30, true],
            'equivalent offset' => ['2026-11-03T04:00:00-05:00', 30, true],
            'offset is outside hours' => ['2026-11-03T09:00:00+05:00', 30, false],
            'seconds past closing' => ['2026-11-03 16:30:01', 30, false],
        ];
    }

    public function test_date_overrides_replace_weekly_hours_and_breaks_and_support_time_off(): void
    {
        $schedule = $this->schedule();
        $schedule['exceptions'] = [
            ['date' => '2026-11-03', 'working_hours' => [], 'breaks' => []],
            ['date' => '2026-11-04', 'working_hours' => [$this->period('10:00', '14:00')],
                'breaks' => [$this->period('11:00', '12:00')]],
            ['date' => '2026-11-10', 'working_hours' => [$this->period('12:00', '14:00')], 'breaks' => []],
        ];
        $this->putJson($this->url(), $schedule)->assertOk();
        foreach (['2026-11-03 09:00:00', '2026-11-04 09:00:00', '2026-11-04 11:15:00'] as $start) {
            $this->postJson('/api/appointments', $this->payload($start))->assertUnprocessable();
        }
        foreach (['2026-11-04 10:00:00', '2026-11-04 12:00:00', '2026-11-10 12:00:00'] as $start) {
            $this->postJson('/api/appointments', $this->payload($start))->assertCreated();
        }
        $this->assertDatabaseCount('appointment', 3);
    }

    public function test_cross_midnight_requires_continuous_coverage_and_checks_the_next_date_exception(): void
    {
        $schedule = ['weekly_hours' => [
            ['day_of_week' => 2, ...$this->period('22:00', '24:00')],
            ['day_of_week' => 3, ...$this->period('00:00', '02:00')],
        ], 'breaks' => [], 'exceptions' => []];
        $this->putJson($this->url(), $schedule)->assertOk();
        $payload = $this->payload('2026-11-03 23:30:00', 60);
        $response = $this->postJson('/api/appointments', $payload)->assertCreated();
        Appointment::findOrFail($response->json('data.appointment.id'))->delete();
        $schedule['exceptions'] = [['date' => '2026-11-04', 'working_hours' => [], 'breaks' => []]];
        $this->putJson($this->url(), $schedule)->assertOk();
        $this->postJson('/api/appointments', $payload)->assertUnprocessable();
        $this->postJson('/api/appointments', $this->payload('2026-11-03 23:30:00', 30))->assertCreated();
    }

    public function test_rescheduling_obeys_date_overrides_and_rejections_leave_no_success_audit(): void
    {
        $schedule = $this->schedule();
        $schedule['exceptions'] = [
            ['date' => '2026-11-03', 'working_hours' => [], 'breaks' => []],
            ['date' => '2026-11-04', 'working_hours' => [$this->period('10:00', '14:00')],
                'breaks' => [$this->period('11:00', '12:00')]],
        ];
        $this->putJson($this->url(), $schedule)->assertOk();
        $appointment = Appointment::factory()->create(['dentist_id' => $this->dentist->id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-11-10 10:00:00', 'duration_minutes' => 30]);
        $before = $appointment->refresh()->getAttributes();
        foreach (['2026-11-03 10:00:00', '2026-11-04 11:00:00'] as $start) {
            $this->patchJson("/api/appointments/{$appointment->id}", ['startsAt' => $start])->assertUnprocessable();
            $this->assertSame($before, $appointment->refresh()->getAttributes());
        }
        $this->assertSame(0, AuditLog::where('resource_type', 'appointments')->count());
        $this->patchJson("/api/appointments/{$appointment->id}", ['startsAt' => '2026-11-04 12:00:00'])->assertOk();
        $this->assertSame('2026-11-04 12:00:00', $appointment->refresh()->appointment_date_time->format('Y-m-d H:i:s'));
    }

    public function test_configuration_requires_authentication(): void
    {
        auth()->forgetGuards();
        foreach (['GET', 'PUT'] as $method) {
            $this->json($method, $this->url(), $this->schedule())->assertUnauthorized();
        }
        $this->assertDatabaseCount('provider_availabilities', 0);
    }

    public function test_adjacent_and_overlapping_working_windows_merge_but_gaps_do_not(): void
    {
        $schedule = ['weekly_hours' => [
            ['day_of_week' => 2, ...$this->period('11:00', '12:00')],
            ['day_of_week' => 2, ...$this->period('09:00', '10:00')],
            ['day_of_week' => 2, ...$this->period('10:00', '11:30')],
            ['day_of_week' => 2, ...$this->period('13:00', '17:00')],
        ], 'breaks' => [], 'exceptions' => []];
        $this->putJson($this->url(), $schedule)->assertOk();
        $this->postJson('/api/appointments', $this->payload('2026-11-03 09:00:00', 180))->assertCreated();
        $this->postJson('/api/appointments', $this->payload('2026-11-03 12:00:00', 90))->assertUnprocessable();
    }

    public function test_unconfigured_providers_keep_legacy_behavior_and_an_empty_schedule_closes_all_days(): void
    {
        $this->postJson('/api/appointments', $this->payload('2026-11-03 03:00:00'))->assertCreated();
        $this->putJson($this->url(), ['weekly_hours' => [], 'breaks' => [], 'exceptions' => []])->assertOk();
        $this->postJson('/api/appointments', $this->payload('2026-11-04 03:00:00'))->assertUnprocessable();
    }

    public function test_hours_revalidate_provider_duration_treatment_and_location_changes(): void
    {
        $this->putJson($this->url(), $this->schedule())->assertOk();
        $target = Appointment::factory()->create([
            'dentist_id' => $this->dentist->id, 'status' => 'scheduled',
            'appointment_date_time' => '2026-11-03 16:30:00', 'duration_minutes' => 30,
        ]);
        $this->patchJson("/api/appointments/{$target->id}", ['duration_minutes' => 60])->assertUnprocessable();
        $treatment = Treatment::factory()->create(['duration_minutes' => 60, 'active' => true]);
        $this->patchJson("/api/appointments/{$target->id}", ['treatment_id' => $treatment->id])->assertUnprocessable();
        $other = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-11-03 08:00:00']);
        $this->patchJson("/api/appointments/{$other->id}", ['dentist_id' => $this->dentist->id])->assertUnprocessable();
        $this->putJson($this->url(), ['weekly_hours' => [], 'breaks' => [], 'exceptions' => []])->assertOk();
        $chair = Chair::factory()->create();
        $this->patchJson("/api/appointments/{$target->id}", ['chair_id' => $chair->id])->assertUnprocessable();
        $this->assertSame(30, $target->refresh()->duration_minutes);
        $this->assertNotSame($treatment->id, $target->treatment_id);
        $this->assertNull($target->chair_id);
    }

    public function test_schedule_changes_preserve_existing_appointments_and_allow_notes_and_workflow_actions(): void
    {
        $appointment = Appointment::factory()->create(['dentist_id' => $this->dentist->id, 'status' => 'scheduled']);
        $other = Appointment::factory()->create(['dentist_id' => $this->dentist->id, 'status' => 'scheduled',
            'appointment_date_time' => $appointment->appointment_date_time->copy()->addDays(2)]);
        $this->putJson($this->url(), ['weekly_hours' => [], 'breaks' => [], 'exceptions' => []])->assertOk();
        $this->patchJson("/api/appointments/{$appointment->id}", ['description' => 'Updated notes'])->assertOk();
        $this->postJson("/api/appointments/{$appointment->id}/check-in")->assertOk();
        $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/appointments/{$appointment->id}/complete")->assertOk();
        $this->postJson("/api/appointments/{$other->id}/cancel")->assertOk();
        $this->assertDatabaseCount('appointment', 2);
    }

    public function test_overlap_checks_still_apply_with_a_configured_schedule(): void
    {
        $this->putJson($this->url(), $this->schedule())->assertOk();
        $payload = $this->payload('2026-11-03 09:00:00');
        $this->postJson('/api/appointments', $payload)->assertCreated();
        $this->postJson('/api/appointments', $payload)->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $chair = Chair::factory()->create();
        Appointment::factory()->create(['chair_id' => $chair->id, 'room_id' => $chair->room_id,
            'status' => 'scheduled', 'appointment_date_time' => '2026-11-03 10:00:00']);
        $this->postJson('/api/appointments', [...$this->payload('2026-11-03 10:00:00'), 'chair_id' => $chair->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['room_id', 'chair_id']);
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_configuration_is_rejected_without_mutation(array $changes, string $error): void
    {
        $this->putJson($this->url(), array_replace($this->schedule(), $changes))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('provider_availabilities', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidSchedules(): array
    {
        $period = ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'];

        return [
            'null hours' => [['weekly_hours' => null], 'weekly_hours'],
            'invalid day' => [['weekly_hours' => [array_replace($period, ['day_of_week' => 7])]], 'weekly_hours.0.day_of_week'],
            'zero interval' => [['weekly_hours' => [array_replace($period, ['end_time' => '09:00'])]], 'weekly_hours.0.end_time'],
            'overnight' => [['weekly_hours' => [array_replace($period, ['start_time' => '22:00', 'end_time' => '02:00'])]], 'weekly_hours.0.end_time'],
            'invalid time' => [['weekly_hours' => [array_replace($period, ['end_time' => '24:01'])]], 'weekly_hours.0.end_time'],
            'missing start' => [['breaks' => [['day_of_week' => 2, 'end_time' => '13:00']]], 'breaks.0.start_time'],
            'invalid date' => [['exceptions' => [['date' => '2026-02-30', 'working_hours' => [], 'breaks' => []]]], 'exceptions.0.date'],
            'duplicate dates' => [['exceptions' => array_fill(0, 2, ['date' => '2026-11-03', 'working_hours' => [], 'breaks' => []])], 'exceptions.0.date'],
            'missing override hours' => [['exceptions' => [['date' => '2026-11-03', 'breaks' => []]]], 'exceptions.0.working_hours'],
            'invalid override break' => [['exceptions' => [['date' => '2026-11-03', 'working_hours' => [],
                'breaks' => [['start_time' => '13:00', 'end_time' => '12:00']]]]], 'exceptions.0.breaks.0.end_time'],
            'unknown interval field' => [['weekly_hours' => [[...$period, 'room_id' => 1]]], 'weekly_hours.0'],
            'keyed intervals' => [['weekly_hours' => ['a' => $period]], 'weekly_hours'],
            'client ownership' => [['tenant_id' => 999], 'tenant_id'],
        ];
    }

    public function test_replacement_requires_all_three_lists(): void
    {
        $this->putJson($this->url(), ['weekly_hours' => []])->assertUnprocessable()
            ->assertJsonValidationErrors(['breaks', 'exceptions']);
    }

    public function test_read_and_write_permissions_are_independent_and_scoped_to_the_clinic(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scheduler', 'slug' => 'scheduler']);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), $this->schedule())->assertForbidden();
        $view = Permission::create(['slug' => 'provider_availabilities.view', 'name' => 'Read schedules']);
        $role->permissions()->attach($view);
        $user->unsetRelation('roles');
        $this->getJson($this->url())->assertOk();
        $this->putJson($this->url(), $this->schedule())->assertForbidden();
        $update = Permission::create(['slug' => 'provider_availabilities.update', 'name' => 'Update schedules']);
        $role->permissions()->sync([$update->id]);
        $user->unsetRelation('roles');
        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), $this->schedule())->assertOk();

        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Dentist::factory()->create());
        Sanctum::actingAs($this->actor);
        foreach (['GET', 'PUT'] as $method) {
            $this->json($method, "/api/dentists/{$foreign->id}/availability", $this->schedule())->assertNotFound();
        }
        $this->assertDatabaseCount('provider_availabilities', 1);
    }

    public function test_foreign_schedule_cannot_be_written_through_raw_database_access(): void
    {
        $tenant = Tenant::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('provider_availabilities')->insert([
            'tenant_id' => $tenant->id, 'dentist_id' => $this->dentist->id,
            'weekly_hours' => '[]', 'breaks' => '[]', 'exceptions' => '[]',
        ]);
    }

    public function test_audit_failure_rolls_back_configuration_changes(): void
    {
        $this->putJson($this->url(), $this->schedule())->assertOk();
        $this->mock(AuditLogService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });
        $this->putJson($this->url(), ['weekly_hours' => [], 'breaks' => [], 'exceptions' => []])->assertStatus(500);
        $this->assertEquals($this->schedule()['weekly_hours'], ProviderAvailability::sole()->weekly_hours);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    private function url(): string
    {
        return "/api/dentists/{$this->dentist->id}/availability";
    }

    private function schedule(): array
    {
        return ['weekly_hours' => [['day_of_week' => 2, ...$this->period('09:00', '17:00')]],
            'breaks' => [['day_of_week' => 2, ...$this->period('12:00', '13:00')]], 'exceptions' => []];
    }

    private function period(string $start, string $end): array
    {
        return ['start_time' => $start, 'end_time' => $end];
    }

    private function payload(string $start, int $duration = 30): array
    {
        return ['patient_id' => Patient::factory()->create()->id, 'dentist_id' => $this->dentist->id,
            'appointment_date_time' => $start, 'duration_minutes' => $duration,
            'appointment_type' => 'Checkup', 'description' => 'Availability test'];
    }
}
