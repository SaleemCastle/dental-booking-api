<?php

namespace Tests\Feature\Api;

use App\Events\ClinicalNoteChanged;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ClinicalNoteWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalNoteApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_clinical_note_endpoints_require_authentication(string $method, string $path): void
    {
        $note = ClinicalNote::factory()->create();
        $this->json($method, str_replace('{id}', $note->id, $path))
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('endpoints')]
    public function test_users_without_roles_or_permissions_cannot_access_notes(string $method, string $path): void
    {
        $this->actingAsUser();
        $note = ClinicalNote::factory()->create();
        $this->json($method, str_replace('{id}', $note->id, $path))
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/clinical-notes'], ['POST', '/api/clinical-notes'],
            ['GET', '/api/clinical-notes/{id}'], ['PATCH', '/api/clinical-notes/{id}'],
            ['PUT', '/api/clinical-notes/{id}'], ['DELETE', '/api/clinical-notes/{id}'],
        ];
    }

    #[DataProvider('clinicalRoles')]
    public function test_dentists_and_admins_can_perform_crud_with_server_attribution(string $role): void
    {
        $author = $this->actingAsUser($role);
        $appointment = Appointment::factory()->create();
        Event::fake([ClinicalNoteChanged::class]);
        $response = $this->postJson('/api/clinical-notes', $this->payload($appointment))
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('statusCode', 201)
            ->assertJsonPath('data.clinicalNote.patient_id', $appointment->patient_id)
            ->assertJsonPath('data.clinicalNote.dentist_id', $appointment->dentist_id)
            ->assertJsonPath('data.clinicalNote.appointment_id', $appointment->id)
            ->assertJsonPath('data.clinicalNote.author_user_id', $author->id)
            ->assertJsonPath('data.clinicalNote.updated_by_user_id', $author->id)
            ->assertJsonPath('data.clinicalNote.authorName', $author->name)
            ->assertJsonStructure(['requestId', 'data' => ['clinicalNote' => ['created_at', 'updated_at']]]);
        $identifier = $response->json('data.clinicalNote.id');
        $this->getJson("/api/clinical-notes/{$identifier}")->assertOk()->assertJsonPath('data.clinicalNote.id', $identifier);

        $editor = $this->actingAsUser($role);
        $this->patchJson("/api/clinical-notes/{$identifier}", ['note_text' => 'Updated clinical findings.'])
            ->assertOk()->assertJsonPath('data.clinicalNote.note_text', 'Updated clinical findings.')
            ->assertJsonPath('data.clinicalNote.author_user_id', $author->id)
            ->assertJsonPath('data.clinicalNote.updated_by_user_id', $editor->id)
            ->assertJsonPath('data.clinicalNote.updatedByName', $editor->name)
            ->assertJsonPath('data.clinicalNote.appointment_id', $appointment->id);
        $this->putJson("/api/clinical-notes/{$identifier}", ['note_text' => 'Final clinical findings.'])->assertOk();
        $this->getJson('/api/clinical-notes')->assertOk()->assertJsonCount(1, 'data.clinicalNotes');
        $this->deleteJson("/api/clinical-notes/{$identifier}")->assertOk();
        $this->assertSoftDeleted('clinical_notes', ['id' => $identifier, 'author_user_id' => $author->id, 'deleted_by_user_id' => $editor->id]);
        $this->getJson('/api/clinical-notes')->assertOk()->assertJsonCount(0, 'data.clinicalNotes');
        $this->getJson("/api/clinical-notes/{$identifier}")->assertNotFound();
        $this->patchJson("/api/clinical-notes/{$identifier}", ['note_text' => 'Cannot restore'])->assertNotFound();
        $this->deleteJson("/api/clinical-notes/{$identifier}")->assertNotFound();

        Event::assertDispatched(ClinicalNoteChanged::class, fn ($event) => $event->action === 'created'
            && $event->actorId === $author->id && $event->before === [] && $event->after['author_user_id'] === $author->id);
        Event::assertDispatched(ClinicalNoteChanged::class, fn ($event) => $event->action === 'updated'
            && $event->actorId === $editor->id && $event->before['note_text'] === 'Patient reported sensitivity.'
            && $event->after['note_text'] === 'Updated clinical findings.');
        Event::assertDispatched(ClinicalNoteChanged::class, fn ($event) => $event->action === 'deleted'
            && $event->after['deleted_at'] !== null && $event->after['deleted_by_user_id'] === $editor->id);
        Event::assertDispatchedTimes(ClinicalNoteChanged::class, 4);
    }

    public static function clinicalRoles(): array
    {
        return [[Role::DENTIST], [Role::ADMIN]];
    }

    #[DataProvider('forbiddenWriters')]
    public function test_non_clinical_writers_and_receptionists_cannot_bypass_the_policy(?string $role, string $permission): void
    {
        $this->actingAsUser($role, $permission);
        $note = ClinicalNote::factory()->create();
        $original = $note->refresh()->getAttributes();
        Event::fake([ClinicalNoteChanged::class]);
        $this->postJson('/api/clinical-notes', $this->payload($note->appointment))->assertForbidden();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['note_text' => 'Unauthorized change'])->assertForbidden();
        $this->putJson("/api/clinical-notes/{$note->id}", ['note_text' => 'Unauthorized change'])->assertForbidden();
        $this->deleteJson("/api/clinical-notes/{$note->id}")->assertForbidden();
        $this->assertSame($original, $note->refresh()->getAttributes());
        $this->assertDatabaseCount('clinical_notes', 1);
        Event::assertNotDispatched(ClinicalNoteChanged::class);
    }

    public static function forbiddenWriters(): array
    {
        return [
            'receptionist' => [Role::RECEPTIONIST, 'clinical_notes.view'],
            'receptionist global wildcard' => [Role::RECEPTIONIST, '*'],
            'receptionist resource wildcard' => [Role::RECEPTIONIST, 'clinical_notes.*'],
            'receptionist create action' => [Role::RECEPTIONIST, '*.create'],
            'receptionist update action' => [Role::RECEPTIONIST, '*.update'],
            'receptionist delete action' => [Role::RECEPTIONIST, '*.delete'],
            'receptionist direct create' => [Role::RECEPTIONIST, 'create'],
            'receptionist direct update' => [Role::RECEPTIONIST, 'update'],
            'receptionist direct delete' => [Role::RECEPTIONIST, 'delete'],
            'receptionist explicit create' => [Role::RECEPTIONIST, 'clinical_notes.create'],
            'receptionist explicit update' => [Role::RECEPTIONIST, 'clinical_notes.update'],
            'receptionist explicit delete' => [Role::RECEPTIONIST, 'clinical_notes.delete'],
            'custom wildcard holder' => [null, '*'],
            'hygienist wildcard holder' => [Role::HYGIENIST, 'clinical_notes.*'],
            'billing wildcard holder' => [Role::BILLING, '*'],
            'auditor wildcard holder' => [Role::AUDITOR, '*'],
        ];
    }

    public function test_receptionist_role_still_denies_writes_when_combined_with_dentist(): void
    {
        $user = $this->actingAsUser(Role::DENTIST, '*');
        $role = Role::create(['name' => 'Receptionist', 'slug' => Role::RECEPTIONIST]);
        $user->roles()->attach($role);
        $user->unsetRelation('roles');
        $this->postJson('/api/clinical-notes', $this->payload())->assertForbidden();
    }

    public function test_explicit_view_permission_allows_read_only_clinical_review(): void
    {
        $this->actingAsUser(Role::AUDITOR, 'clinical_notes.view');
        $note = ClinicalNote::factory()->create();
        $this->getJson('/api/clinical-notes')->assertOk()->assertJsonCount(1, 'data.clinicalNotes');
        $this->getJson("/api/clinical-notes/{$note->id}")->assertOk();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['note_text' => 'Read only'])->assertForbidden();
    }

    public function test_appointment_is_optional_and_null_can_explicitly_unlink_it(): void
    {
        $this->actingAsUser(Role::DENTIST);
        $payload = $this->payload();
        unset($payload['appointment_id']);
        $response = $this->postJson('/api/clinical-notes', $payload)->assertCreated()
            ->assertJsonPath('data.clinicalNote.appointment_id', null);
        $identifier = $response->json('data.clinicalNote.id');
        $appointment = Appointment::factory()->create(['patient_id' => $payload['patient_id'], 'dentist_id' => $payload['dentist_id']]);
        $this->patchJson("/api/clinical-notes/{$identifier}", ['appointment_id' => $appointment->id])->assertOk();
        $this->patchJson("/api/clinical-notes/{$identifier}", ['note_text' => 'Preserved appointment'])
            ->assertOk()->assertJsonPath('data.clinicalNote.appointment_id', $appointment->id);
        $this->patchJson("/api/clinical-notes/{$identifier}", ['appointment_id' => null])
            ->assertOk()->assertJsonPath('data.clinicalNote.appointment_id', null);
    }

    public function test_linked_patient_and_provider_must_match_on_create_and_partial_update(): void
    {
        $this->actingAsUser(Role::DENTIST);
        $appointment = Appointment::factory()->create();
        $otherPatient = Patient::factory()->create();
        $otherProvider = Dentist::factory()->create();
        $payload = $this->payload($appointment);
        foreach (['patient_id' => $otherPatient->id, 'dentist_id' => $otherProvider->id] as $field => $value) {
            $this->postJson('/api/clinical-notes', array_replace($payload, [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $note = ClinicalNote::factory()->create([
            'appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id, 'dentist_id' => $appointment->dentist_id,
        ]);
        $original = $note->refresh()->getAttributes();
        Event::fake([ClinicalNoteChanged::class]);
        foreach (['patient_id' => $otherPatient->id, 'dentist_id' => $otherProvider->id] as $field => $value) {
            $this->patchJson("/api/clinical-notes/{$note->id}", [$field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $other = Appointment::factory()->create();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['appointment_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['patient_id', 'dentist_id']);
        $this->assertSame($original, $note->refresh()->getAttributes());
        Event::assertNotDispatched(ClinicalNoteChanged::class);

        $this->putJson("/api/clinical-notes/{$note->id}", $this->payload($other))
            ->assertOk()->assertJsonPath('data.clinicalNote.patient_id', $other->patient_id)
            ->assertJsonPath('data.clinicalNote.dentist_id', $other->dentist_id)
            ->assertJsonPath('data.clinicalNote.appointment_id', $other->id);
    }

    #[DataProvider('invalidFields')]
    public function test_clinical_note_fields_are_validated_for_create_and_update(string $field, mixed $value): void
    {
        $this->actingAsUser(Role::DENTIST);
        $note = ClinicalNote::factory()->create();
        $original = $note->refresh()->getAttributes();
        $this->postJson('/api/clinical-notes', array_replace($this->payload(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/clinical-notes/{$note->id}", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $note->refresh()->getAttributes());
    }

    public static function invalidFields(): array
    {
        return [
            ['patient_id', null], ['patient_id', 999999], ['dentist_id', null], ['dentist_id', 999999],
            ['appointment_id', 999999], ['appointment_id', ['invalid']], ['note_text', ' '],
            ['note_text', null], ['note_text', ['invalid']], ['note_text', str_repeat('a', 16001)],
            ['author_user_id', 1], ['author_user_id', null], ['updated_by_user_id', 1],
            ['deleted_by_user_id', 1], ['deleted_at', null], ['created_at', '2026-10-16'], ['updated_at', '2026-10-16'],
        ];
    }

    public function test_creation_requires_patient_provider_and_note_text(): void
    {
        $this->actingAsUser(Role::DENTIST);
        $this->postJson('/api/clinical-notes', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id', 'dentist_id', 'note_text']);
    }

    public function test_notes_are_filtered_and_paginated_with_deleted_notes_excluded(): void
    {
        $this->actingAsUser(Role::DENTIST);
        $appointment = Appointment::factory()->create();
        $notes = ClinicalNote::factory(3)->create([
            'patient_id' => $appointment->patient_id, 'dentist_id' => $appointment->dentist_id, 'appointment_id' => $appointment->id,
        ]);
        ClinicalNote::factory()->create();
        $notes->first()->delete();
        $this->getJson('/api/clinical-notes?patient_id='.$appointment->patient_id.'&dentist_id='.$appointment->dentist_id.'&appointment_id='.$appointment->id.'&per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data.clinicalNotes')
            ->assertJsonPath('data.clinicalNotes.0.id', $notes[1]->id)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.currentPage', 2)
            ->assertJsonPath('meta.pagination.perPage', 1)
            ->assertJsonPath('meta.pagination.lastPage', 2);
        $this->getJson('/api/clinical-notes?per_page=101&page=0&patient_id=999999&dentist_id=999999&appointment_id=999999')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page', 'patient_id', 'dentist_id', 'appointment_id']);
    }

    public function test_missing_notes_return_not_found(): void
    {
        $this->actingAsUser(Role::DENTIST);
        foreach (['GET', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->json($method, '/api/clinical-notes/999999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        }
    }

    public function test_legacy_unknown_author_remains_unknown_when_the_note_is_edited(): void
    {
        $actor = $this->actingAsUser(Role::DENTIST);
        $note = ClinicalNote::factory()->create(['author_user_id' => null, 'updated_by_user_id' => null]);
        $this->getJson("/api/clinical-notes/{$note->id}")->assertOk()->assertJsonPath('data.clinicalNote.authorName', null);
        $this->patchJson("/api/clinical-notes/{$note->id}", ['note_text' => 'Historical correction'])
            ->assertOk()->assertJsonPath('data.clinicalNote.author_user_id', null)
            ->assertJsonPath('data.clinicalNote.updated_by_user_id', $actor->id);
    }

    public function test_noop_updates_preserve_attribution_and_do_not_emit_audit_events(): void
    {
        $this->actingAsUser(Role::DENTIST);
        $note = ClinicalNote::factory()->create();
        $editor = $note->updated_by_user_id;
        Event::fake([ClinicalNoteChanged::class]);
        $this->patchJson("/api/clinical-notes/{$note->id}", [])->assertOk()
            ->assertJsonPath('data.clinicalNote.updated_by_user_id', $editor);
        Event::assertNotDispatched(ClinicalNoteChanged::class);
    }

    public function test_rolled_back_writes_do_not_emit_audit_events(): void
    {
        $actor = $this->actingAsUser(Role::DENTIST);
        $payload = $this->payload();
        Event::fake([ClinicalNoteChanged::class]);
        DB::beginTransaction();
        app(ClinicalNoteWorkflow::class)->create($payload, $actor);
        Event::assertNotDispatched(ClinicalNoteChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(ClinicalNoteChanged::class);
        $this->assertDatabaseCount('clinical_notes', 0);
    }

    public function test_note_history_prevents_parent_reassignment_and_deletion_even_after_soft_deletion(): void
    {
        $this->actingAsUser(Role::ADMIN);
        $note = ClinicalNote::factory()->create();
        $appointment = $note->appointment;
        $this->patchJson("/api/appointments/{$appointment->id}", ['patient_id' => Patient::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->patchJson("/api/appointments/{$appointment->id}", ['dentist_id' => Dentist::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->deleteJson("/api/clinical-notes/{$note->id}")->assertOk();
        $this->deleteJson("/api/appointments/{$appointment->id}")->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->deleteJson("/api/dentists/{$note->dentist_id}")->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $this->assertDatabaseHas('appointment', ['id' => $appointment->id, 'patient_id' => $note->patient_id]);
        $this->assertDatabaseHas('dentist', ['id' => $note->dentist_id]);
    }

    private function payload(?Appointment $appointment = null): array
    {
        $appointment ??= Appointment::factory()->create();

        return [
            'patient_id' => $appointment->patient_id, 'dentist_id' => $appointment->dentist_id,
            'appointment_id' => $appointment->id, 'note_text' => 'Patient reported sensitivity.',
        ];
    }

    private function actingAsUser(?string $roleSlug = null, ?string $permissionSlug = null): User
    {
        $user = User::factory()->create();

        if ($roleSlug !== null || $permissionSlug !== null) {
            $role = Role::firstOrCreate(['slug' => $roleSlug ?? 'custom-role'], ['name' => $roleSlug ?? 'Custom role']);

            if ($permissionSlug !== null) {
                [$resource, $action] = array_pad(explode('.', $permissionSlug, 2), 2, null);
                $permission = Permission::firstOrCreate(['slug' => $permissionSlug], [
                    'name' => $permissionSlug, 'resource' => $resource, 'action' => $action,
                ]);
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }

            $user->roles()->attach($role);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
