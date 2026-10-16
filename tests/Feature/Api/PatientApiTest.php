<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
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
        $this->actingAsUserWithPatientPermissions('patients.view');
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
                        'is_archived',
                        'archived_at',
                        'archived_by_user_id',
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
            ->assertJsonPath('data.patients.0.allergies', [])
            ->assertJsonPath('meta.pagination.currentPage', 1)
            ->assertJsonPath('meta.pagination.perPage', 15)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_patients_index_is_paginated(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        Patient::factory(3)->sequence(
            ['firstName' => 'Alice'],
            ['firstName' => 'Bianca'],
            ['firstName' => 'Carla'],
        )->create();

        $this->getJson('/api/patients?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.firstName', 'Carla')
            ->assertJsonPath('meta.pagination.currentPage', 2)
            ->assertJsonPath('meta.pagination.perPage', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.lastPage', 2)
            ->assertJsonPath('meta.pagination.hasMorePages', false);
    }

    public function test_patients_can_be_searched_by_name_contact_and_record_identifier(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        $target = Patient::factory()->create([
            'firstName' => 'Sofia',
            'lastName' => 'Martinez',
            'emergency_contact_name' => 'Camila Martinez',
            'emergency_contact_phone' => '+5717001234',
        ]);
        Patient::factory()->create([
            'firstName' => 'Unrelated',
            'lastName' => 'Patient',
            'emergency_contact_phone' => '+5719999999',
        ]);

        $this->getJson('/api/patients?search=Sofia%20Martinez')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.id', $target->id);

        $this->getJson('/api/patients?search=7001234')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.id', $target->id);

        Patient::query()->update([
            'streetAddress' => 'Alpha Street',
            'town' => 'Alpha Town',
            'city' => 'Alpha City',
            'notes' => 'Alpha notes',
            'emergency_contact_name' => 'Alpha Contact',
            'emergency_contact_phone' => null,
            'emergency_contact_relationship' => 'Alpha Relationship',
        ]);

        $this->getJson("/api/patients?search={$target->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.id', $target->id);
    }

    public function test_patient_search_and_pagination_can_be_combined(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        Patient::factory()->create(['firstName' => 'ArchiveMatch', 'archived_at' => now()]);
        Patient::factory(3)->sequence(
            ['firstName' => 'SearchMatchOne'],
            ['firstName' => 'SearchMatchTwo'],
            ['firstName' => 'SearchMatchThree'],
        )->create();

        $this->getJson('/api/patients?search=SearchMatch&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.firstName', 'SearchMatchThree')
            ->assertJsonPath('meta.pagination.currentPage', 2)
            ->assertJsonPath('meta.pagination.perPage', 2)
            ->assertJsonPath('meta.pagination.total', 3);

        $this->getJson('/api/patients?archived=only&search=ArchiveMatch&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.firstName', 'ArchiveMatch')
            ->assertJsonPath('data.patients.0.is_archived', true)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_single_patient_response_is_direct_patient_object(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        $patient = Patient::factory()->create(['firstName' => 'Jane']);

        $this->getJson("/api/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.patient.firstName', 'Jane');
    }

    public function test_patients_can_be_created_with_medical_profile_fields(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.create');

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
        $this->actingAsUserWithPatientPermissions(['patients.update', 'patients.medical.update']);
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
        $this->actingAsUserWithPatientPermissions('patients.create');

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
        $this->actingAsUserWithPatientPermissions('patients.view');

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

    public function test_patient_archive_requires_authorization(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        $patient = Patient::factory()->create();

        $this->deleteJson("/api/patients/{$patient->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertNull($patient->refresh()->archived_at);
    }

    public function test_patient_can_be_archived_without_deleting_linked_history(): void
    {
        $user = $this->actingAsUserWithPatientPermissions('patients.archive');
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        $clinicalNote = ClinicalNote::factory()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
        ]);

        $this->deleteJson("/api/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Patient successfully archived.')
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.patient.is_archived', true)
            ->assertJsonPath('data.patient.archived_by_user_id', $user->id);

        $this->assertDatabaseHas('patient', [
            'id' => $patient->id,
            'archived_by_user_id' => $user->id,
        ]);
        $this->assertNotNull($patient->refresh()->archived_at);
        $this->assertSame($patient->id, $appointment->refresh()->patient_id);
        $this->assertSame($patient->id, $clinicalNote->refresh()->patient_id);
    }

    public function test_archived_state_is_queryable_from_patient_index(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.view');
        $activePatient = Patient::factory()->create(['firstName' => 'Active']);
        $archivedPatient = Patient::factory()->create([
            'firstName' => 'Archived',
            'archived_at' => now(),
        ]);

        $this->getJson('/api/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.id', $activePatient->id)
            ->assertJsonPath('data.patients.0.is_archived', false);

        $this->getJson('/api/patients?archived=only')
            ->assertOk()
            ->assertJsonCount(1, 'data.patients')
            ->assertJsonPath('data.patients.0.id', $archivedPatient->id)
            ->assertJsonPath('data.patients.0.is_archived', true);

        $this->getJson('/api/patients?archived=with')
            ->assertOk()
            ->assertJsonCount(2, 'data.patients');
    }

    public function test_general_patient_update_permission_cannot_update_medical_fields(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.update');
        $patient = Patient::factory()->create([
            'firstName' => 'Before',
            'allergies' => ['Latex'],
        ]);

        $this->patchJson("/api/patients/{$patient->id}", [
            'firstName' => 'After',
            'allergies' => ['Penicillin'],
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN');

        $patient->refresh();

        $this->assertSame('Before', $patient->firstName);
        $this->assertSame(['Latex'], $patient->allergies);
    }

    public function test_medical_patient_update_permission_cannot_update_general_fields(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.medical.update');
        $patient = Patient::factory()->create([
            'firstName' => 'Before',
            'allergies' => ['Latex'],
        ]);

        $this->patchJson("/api/patients/{$patient->id}", [
            'firstName' => 'After',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame('Before', $patient->refresh()->firstName);
    }

    public function test_medical_patient_update_permission_can_update_medical_fields(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.medical.update');
        $patient = Patient::factory()->create([
            'allergies' => ['Latex'],
        ]);

        $this->patchJson("/api/patients/{$patient->id}", [
            'allergies' => ['Penicillin'],
            'medications' => ['Metformin'],
            'medical_alerts' => ['Diabetes'],
        ])
            ->assertOk()
            ->assertJsonPath('data.patient.allergies.0', 'Penicillin')
            ->assertJsonPath('data.patient.medications.0', 'Metformin')
            ->assertJsonPath('data.patient.medical_alerts.0', 'Diabetes');
    }

    public function test_patient_archive_requires_archive_permission_not_delete_permission(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.delete');
        $patient = Patient::factory()->create();

        $this->deleteJson("/api/patients/{$patient->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertNull($patient->refresh()->archived_at);
    }

    public function test_restricted_archive_fields_are_not_mass_assignable_through_update(): void
    {
        $this->actingAsUserWithPatientPermissions('patients.update');
        $patient = Patient::factory()->create();

        $this->patchJson("/api/patients/{$patient->id}", [
            'firstName' => 'Changed',
            'archived_at' => now()->toISOString(),
            'archived_by_user_id' => User::factory()->create()->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.patient.firstName', 'Changed')
            ->assertJsonPath('data.patient.is_archived', false)
            ->assertJsonPath('data.patient.archived_at', null)
            ->assertJsonPath('data.patient.archived_by_user_id', null);
    }

    private function actingAsUserWithPatientPermissions(string|array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Patient Test Role',
            'slug' => 'patient-test-role-'.uniqid(),
        ]);

        foreach ((array) $permissions as $permissionSlug) {
            [$resource, $action] = array_pad(explode('.', $permissionSlug, 2), 2, null);

            $permission = Permission::firstOrCreate(
                ['slug' => $permissionSlug],
                [
                    'name' => str($permissionSlug)->replace(['.', '_'], ' ')->title()->toString(),
                    'resource' => $resource,
                    'action' => $action,
                ],
            );

            $role->permissions()->attach($permission);
        }

        $user->roles()->attach($role);

        Sanctum::actingAs($user);

        return $user;
    }
}
