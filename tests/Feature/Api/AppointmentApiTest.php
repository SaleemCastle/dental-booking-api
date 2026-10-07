<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_filtered_appointments_include_display_ready_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
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
        Sanctum::actingAs(User::factory()->create());
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
}
