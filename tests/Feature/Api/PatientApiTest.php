<?php

namespace Tests\Feature\Api;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_patients_require_authentication(): void
    {
        $this->getJson('/api/patients')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_patients_index_matches_frontend_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Patient::factory()->create([
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'allergies' => null,
            'medications' => null,
            'medical_alerts' => null,
        ]);

        $this->getJson('/api/patients')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'statusCode',
                'message',
                'code',
                'data' => [
                    'patients' => [[
                        'id',
                        'firstName',
                        'lastName',
                        'appointments',
                        'streetAddress',
                        'town',
                        'city',
                        'sex',
                        'notes',
                        'emergency_contact_name',
                        'emergency_contact_phone',
                        'emergency_contact_relationship',
                        'allergies',
                        'medications',
                        'medical_alerts',
                        'created_at',
                        'updated_at',
                    ]],
                ],
                'errors',
                'meta',
                'requestId',
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('data.patients.0.firstName', 'Jane')
            ->assertJsonPath('data.patients.0.allergies', []);
    }

    public function test_single_patient_response_is_direct_patient_object(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $patient = Patient::factory()->create(['firstName' => 'Jane']);

        $this->getJson("/api/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.patient.firstName', 'Jane');
    }

    public function test_patients_can_be_created_with_medical_profile_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'firstName' => 'Maria',
            'lastName' => 'Garcia',
            'appointments' => 'Cleaning',
            'sex' => 'Female',
            'streetAddress' => '123 Main Street',
            'town' => 'Midtown',
            'city' => 'Bogota',
            'notes' => 'Prefers morning appointments.',
            'emergency_contact_name' => 'Luis Garcia',
            'emergency_contact_phone' => '+5715551234',
            'emergency_contact_relationship' => 'Spouse',
            'allergies' => ['Penicillin', 'Latex'],
            'medications' => ['Metformin'],
            'medical_alerts' => ['Diabetes'],
        ];

        $this->postJson('/api/patients', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.patient.firstName', 'Maria')
            ->assertJsonPath('data.patient.emergency_contact_name', 'Luis Garcia')
            ->assertJsonPath('data.patient.allergies.0', 'Penicillin')
            ->assertJsonPath('data.patient.medications.0', 'Metformin')
            ->assertJsonPath('data.patient.medical_alerts.0', 'Diabetes');

        $this->assertDatabaseHas('patient', [
            'firstName' => 'Maria',
            'emergency_contact_name' => 'Luis Garcia',
        ]);
    }

    public function test_patients_can_be_updated_with_medical_profile_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $patient = Patient::factory()->create([
            'allergies' => ['Latex'],
            'medications' => [],
            'medical_alerts' => [],
        ]);

        $this->patchJson("/api/patients/{$patient->id}", [
            'emergency_contact_name' => 'Ana Doe',
            'emergency_contact_phone' => '+5715559999',
            'emergency_contact_relationship' => 'Parent',
            'allergies' => ['Aspirin'],
            'medications' => ['Ibuprofen'],
            'medical_alerts' => ['High blood pressure'],
        ])
            ->assertOk()
            ->assertJsonPath('data.patient.emergency_contact_relationship', 'Parent')
            ->assertJsonPath('data.patient.allergies.0', 'Aspirin')
            ->assertJsonPath('data.patient.medications.0', 'Ibuprofen')
            ->assertJsonPath('data.patient.medical_alerts.0', 'High blood pressure');
    }

    public function test_invalid_patient_payloads_return_field_level_errors(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/patients', [
            'firstName' => '',
            'lastName' => 'Doe',
            'appointments' => 'Cleaning',
            'sex' => 'Female',
            'streetAddress' => '123 Main Street',
            'town' => 'Midtown',
            'city' => 'Bogota',
            'notes' => 'Notes.',
            'emergency_contact_phone' => str_repeat('1', 51),
            'allergies' => 'Penicillin',
            'medications' => [123],
            'medical_alerts' => [str_repeat('a', 256)],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors([
                'firstName',
                'emergency_contact_phone',
                'allergies',
                'medications.0',
                'medical_alerts.0',
            ]);
    }

    public function test_existing_patient_records_without_medical_profile_fields_remain_compatible(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $patient = Patient::create([
            'firstName' => 'Legacy',
            'lastName' => 'Patient',
            'appointments' => 'Consultation',
            'sex' => 'Other',
            'streetAddress' => '123 Legacy Street',
            'town' => 'Old Town',
            'city' => 'Bogota',
            'notes' => 'Imported record.',
        ]);

        $this->getJson("/api/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('data.patient.firstName', 'Legacy')
            ->assertJsonPath('data.patient.emergency_contact_name', null)
            ->assertJsonPath('data.patient.allergies', [])
            ->assertJsonPath('data.patient.medications', [])
            ->assertJsonPath('data.patient.medical_alerts', []);
    }
}
