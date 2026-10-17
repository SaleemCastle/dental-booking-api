<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_filtered_appointments_include_display_ready_fields(): void
    {
        $this->actingAsUserWithPermission('appointments.view');
        $patient = Patient::factory()->create(['firstName' => 'Jane', 'lastName' => 'Doe']);
        $dentist = Dentist::factory()->create(['first_name' => 'Alex', 'last_name' => 'Rivera']);
        $treatment = Treatment::factory()->create(['name' => 'Cleaning']);

        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'dentist_id' => $dentist->id,
            'treatment_id' => $treatment->id,
            'appointment_date_time' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
            'status' => 'scheduled',
        ]);

        $this->getJson('/api/appointments?date=2026-10-05')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('data.appointments.0.patientName', 'Jane Doe')
            ->assertJsonPath('data.appointments.0.dentistName', 'Alex Rivera')
            ->assertJsonPath('data.appointments.0.treatmentName', 'Cleaning')
            ->assertJsonPath('data.appointments.0.paymentStatus', 'unpaid');
    }

    public function test_dentist_appointment_times_cannot_overlap(): void
    {
        $this->actingAsUserWithPermission('appointments.create');
        $patient = Patient::factory()->create();
        $dentist = Dentist::factory()->create();
        $treatment = Treatment::factory()->create();

        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'dentist_id' => $dentist->id,
            'treatment_id' => $treatment->id,
            'appointment_date_time' => '2026-10-05 09:00:00',
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);

        $this->postJson('/api/appointments', [
            'patient_id' => $patient->id,
            'dentist_id' => $dentist->id,
            'treatment_id' => $treatment->id,
            'appointment_date_time' => '2026-10-05 09:30:00',
            'appointment_type' => 'Cleaning',
            'description' => 'Routine cleaning.',
            'status' => 'scheduled',
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['appointment_date_time']);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_appointment_endpoints_require_authentication(string $method, string $path, string $permission): void
    {
        $appointment = Appointment::factory()->create();

        $this->json($method, str_replace('{id}', $appointment->id, $path))
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('protectedEndpoints')]
    public function test_appointment_endpoints_require_permission(string $method, string $path, string $permission): void
    {
        Sanctum::actingAs(User::factory()->create());
        $appointment = Appointment::factory()->create();

        $this->json($method, str_replace('{id}', $appointment->id, $path))
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    #[DataProvider('protectedEndpoints')]
    public function test_appointment_endpoints_accept_their_permission(string $method, string $path, string $permission): void
    {
        $this->actingAsUserWithPermission($permission);
        $appointment = Appointment::factory()->create([
            'status' => str_ends_with($path, '/complete') ? 'in_progress' : 'scheduled',
        ]);

        $this->json($method, str_replace('{id}', $appointment->id, $path), [
            'patient_id' => $appointment->patient_id,
            'dentist_id' => $appointment->dentist_id,
            'appointment_date_time' => $appointment->appointment_date_time->copy()->addDay()->toISOString(),
            'appointment_type' => 'Cleaning',
            'description' => 'Routine cleaning.',
        ])->assertStatus($method === 'POST' && $path === '/api/appointments' ? 201 : 200);
    }

    public static function protectedEndpoints(): array
    {
        return [
            'index' => ['GET', '/api/appointments', 'appointments.view'],
            'locations' => ['GET', '/api/appointment-locations', 'appointments.view'],
            'show' => ['GET', '/api/appointments/{id}', 'appointments.view'],
            'create' => ['POST', '/api/appointments', 'appointments.create'],
            'patch' => ['PATCH', '/api/appointments/{id}', 'appointments.update'],
            'put' => ['PUT', '/api/appointments/{id}', 'appointments.update'],
            'check-in' => ['POST', '/api/appointments/{id}/check-in', 'appointments.update'],
            'complete' => ['POST', '/api/appointments/{id}/complete', 'appointments.update'],
            'cancel' => ['POST', '/api/appointments/{id}/cancel', 'appointments.update'],
            'delete' => ['DELETE', '/api/appointments/{id}', 'appointments.delete'],
        ];
    }

    public function test_view_permission_does_not_allow_scheduling_or_transitions(): void
    {
        $this->actingAsUserWithPermission('appointments.view');
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);

        $this->postJson('/api/appointments')->assertForbidden();
        $this->patchJson("/api/appointments/{$appointment->id}")->assertForbidden();
        $this->postJson("/api/appointments/{$appointment->id}/check-in")->assertForbidden();
        $this->postJson("/api/appointments/{$appointment->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/appointments/{$appointment->id}")->assertForbidden();
        $this->assertSame('scheduled', $appointment->refresh()->status);
    }

    private function actingAsUserWithPermission(string $slug): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Scheduler', 'slug' => 'scheduler']);
        [$resource, $action] = explode('.', $slug, 2);
        $permission = Permission::create([
            'name' => $slug, 'slug' => $slug, 'resource' => $resource, 'action' => $action,
        ]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
    }
}
