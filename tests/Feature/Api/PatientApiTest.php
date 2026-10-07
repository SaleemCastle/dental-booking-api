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
            ->assertJsonPath('data.patients.0.firstName', 'Jane');
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
}
