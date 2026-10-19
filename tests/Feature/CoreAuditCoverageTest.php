<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientWorkflow;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoreAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_create_update_and_archive_are_audited_without_patient_data(): void
    {
        $actor = $this->actingAsAdmin();
        $this->withHeader('X-Request-Id', 'patient-audit-123');
        $response = $this->postJson('/api/patients', Patient::factory()->raw())->assertCreated();
        $patientId = $response->json('data.patient.id');
        $this->patchJson("/api/patients/{$patientId}", ['notes' => 'Private medical details'])->assertOk();
        $this->patchJson("/api/patients/{$patientId}", ['notes' => 'Private medical details'])->assertOk();
        $this->deleteJson("/api/patients/{$patientId}")->assertOk();
        $this->deleteJson("/api/patients/{$patientId}/delete")->assertOk();

        $entries = AuditLog::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'archived'], $entries->pluck('action')->all());
        foreach ($entries as $entry) {
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('patients', $entry->resource_type);
            $this->assertSame((string) $patientId, $entry->resource_id);
            $this->assertSame('patient-audit-123', $entry->request_metadata['request_id']);
            $this->assertStringNotContainsString('Private medical details', $entry->toJson());
            $this->assertStringNotContainsString('firstName', $entry->toJson());
        }
    }

    public function test_appointment_create_edit_reschedule_and_status_changes_have_distinct_audits(): void
    {
        $actor = $this->actingAsAdmin();
        $fixture = Appointment::factory()->create(['appointment_date_time' => '2026-10-25 15:00:00']);
        $payload = $this->appointmentPayload($fixture);
        $response = $this->postJson('/api/appointments', $payload)->assertCreated();
        $appointmentId = $response->json('data.appointment.id');
        $path = "/api/appointments/{$appointmentId}";
        $this->patchJson($path, ['description' => 'Private edited appointment details'])->assertOk();
        $this->patchJson($path, ['startsAt' => '2026-10-25T11:00:00Z'])->assertOk()
            ->assertJsonPath('data.appointment.startsAt', '2026-10-25T11:00:00.000000Z');
        $this->patchJson($path, [])->assertOk();
        $this->postJson("{$path}/check-in")->assertOk();
        $this->postJson("{$path}/complete")->assertUnprocessable();
        $this->patchJson($path, ['status' => 'in_progress'])->assertOk();
        $this->postJson("{$path}/complete")->assertOk();
        $this->postJson("{$path}/complete")->assertOk();

        $entries = AuditLog::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'updated', 'status_changed', 'status_changed', 'status_changed'], $entries->pluck('action')->all());
        foreach ($entries as $entry) {
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('appointments', $entry->resource_type);
            $this->assertSame((string) $appointmentId, $entry->resource_id);
            $this->assertStringNotContainsString('Private', $entry->toJson());
        }
    }

    public function test_cancellation_and_combined_status_updates_do_not_duplicate_success_events(): void
    {
        $this->actingAsAdmin();
        $appointment = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-10-25 15:00:00']);
        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'checked_in', 'description' => 'Private change'])->assertOk();
        $this->postJson("/api/appointments/{$appointment->id}/cancel", ['reason' => 'Private cancellation reason'])->assertOk();
        $this->assertSame(['status_changed', 'status_changed'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('Private', AuditLog::all()->toJson());
    }

    public function test_failed_validation_authorization_and_overlap_checks_do_not_emit_success_audits(): void
    {
        $appointment = Appointment::factory()->create(['appointment_date_time' => '2026-10-25 09:00:00', 'status' => 'scheduled']);
        $patient = $appointment->patient;
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/patients/{$patient->id}", ['notes' => 'Denied'])->assertForbidden();
        $this->deleteJson("/api/patients/{$patient->id}")->assertForbidden();
        $this->postJson('/api/appointments', $this->appointmentPayload($appointment))->assertForbidden();
        $this->actingAsAdmin();
        $this->postJson('/api/patients', [])->assertUnprocessable();
        $this->postJson('/api/appointments', $this->appointmentPayload($appointment))->assertUnprocessable();
        $this->patchJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])->assertUnprocessable();
        $target = Appointment::factory()->create(['status' => 'scheduled']);
        $this->patchJson("/api/appointments/{$target->id}", [
            'dentist_id' => $appointment->dentist_id, 'appointment_date_time' => '2026-10-25 09:00:00',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_user_crud_audits_the_target_account_without_credentials_or_profile_values(): void
    {
        $actor = $this->actingAsAdmin();
        $response = $this->postJson('/api/users', [
            'name' => 'Private account name', 'email' => 'private@example.test', 'password' => 'Private-password-123',
        ])->assertCreated();
        $userId = $response->json('data.user.id');
        $this->patchJson("/api/users/{$userId}", ['name' => 'Private renamed account'])->assertOk();
        $this->patchJson("/api/users/{$userId}", [])->assertOk();
        $this->postJson('/api/users', ['name' => 'Duplicate', 'email' => 'private@example.test', 'password' => 'Private-password-123'])->assertUnprocessable();
        $this->deleteJson("/api/users/{$userId}")->assertOk();

        $entries = AuditLog::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $entries->pluck('action')->all());
        foreach ($entries as $entry) {
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('users', $entry->resource_type);
            $this->assertSame((string) $userId, $entry->resource_id);
            $this->assertStringNotContainsString('Private', $entry->toJson());
            $this->assertStringNotContainsString('private@example.test', $entry->toJson());
            $this->assertStringNotContainsString('password', $entry->toJson());
        }
    }

    public function test_public_registration_records_an_explicit_unauthenticated_actor(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'New account', 'email' => 'registration@example.test',
            'password' => 'Private-password-123', 'password_confirmation' => 'Private-password-123',
        ])->assertCreated();
        $entry = AuditLog::sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('created', $entry->action);
        $this->assertSame('users', $entry->resource_type);
        $this->assertSame((string) $response->json('data.user.id'), $entry->resource_id);
        $this->assertSame('register', $entry->request_metadata['route']);
    }

    public function test_password_reset_audits_only_a_successfully_verified_reset_without_copying_secrets(): void
    {
        $user = User::factory()->create();
        Event::fake([PasswordReset::class]);
        $payload = ['email' => $user->email, 'password' => 'Private-password-123', 'password_confirmation' => 'Private-password-123'];
        $this->postJson('/reset-password', ['token' => 'invalid-token', ...$payload])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
        $token = Password::createToken($user);
        $this->postJson('/reset-password', ['token' => $token, ...$payload])->assertOk();
        $this->assertTrue(Hash::check('Private-password-123', $user->refresh()->password));
        $entry = AuditLog::sole();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame((string) $user->id, $entry->resource_id);
        $this->assertSame('password_reset', $entry->action);
        $this->assertStringNotContainsString($token, $entry->toJson());
        $this->assertStringNotContainsString('Private-password-123', $entry->toJson());
        Event::assertDispatched(PasswordReset::class);
    }

    public function test_email_verification_records_only_the_first_valid_verification(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);
        $this->actingAs($user);
        $invalid = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong-email')]);
        $this->getJson($invalid)->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect();
        $this->get($url)->assertRedirect();
        $entry = AuditLog::sole();
        $this->assertSame('email_verified', $entry->action);
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame((string) $user->id, $entry->resource_id);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    #[DataProvider('failingWrites')]
    public function test_audit_storage_failures_do_not_leave_partial_domain_changes(string $operation): void
    {
        $actor = $this->actingAsAdmin();
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['status' => 'scheduled', 'appointment_date_time' => '2026-10-25 15:00:00']);
        $user = User::factory()->unverified()->create();
        $role = Role::create(['name' => 'Custom', 'slug' => 'custom']);
        $permission = Permission::create(['name' => 'View patients', 'slug' => 'patients.view']);
        $role->users()->attach($user);
        $snapshot = [];
        foreach (['patient', 'appointment', 'users', 'roles', 'role_user', 'permission_role'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit storage unavailable.'));

        if ($operation === 'user.password_reset') {
            $this->app['auth']->forgetGuards();
            $this->app['auth']->shouldUse('web');
        } elseif ($operation === 'user.verify_email') {
            $this->actingAs($user);
        }

        $response = match ($operation) {
            'patient.create' => $this->postJson('/api/patients', Patient::factory()->raw()),
            'patient.update' => $this->patchJson("/api/patients/{$patient->id}", ['notes' => 'Uncommitted']),
            'patient.archive' => $this->deleteJson("/api/patients/{$patient->id}"),
            'appointment.create' => $this->postJson('/api/appointments', $this->appointmentPayload($appointment)),
            'appointment.update' => $this->patchJson("/api/appointments/{$appointment->id}", ['description' => 'Uncommitted']),
            'appointment.status' => $this->postJson("/api/appointments/{$appointment->id}/check-in"),
            'user.create' => $this->postJson('/api/users', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'password123']),
            'user.update' => $this->patchJson("/api/users/{$user->id}", ['name' => 'Uncommitted']),
            'user.delete' => $this->deleteJson("/api/users/{$user->id}"),
            'user.password_reset' => $this->postJson('/reset-password', [
                'email' => $user->email, 'token' => Password::createToken($user),
                'password' => 'Private-password-123', 'password_confirmation' => 'Private-password-123',
            ]),
            'user.verify_email' => $this->getJson(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
                'id' => $user->id, 'hash' => sha1($user->email),
            ])),
            'role.create' => $this->postJson('/api/roles', ['name' => 'New', 'slug' => 'new']),
            'role.update' => $this->patchJson("/api/roles/{$role->id}", ['name' => 'Uncommitted']),
            'role.delete' => $this->deleteJson("/api/roles/{$role->id}"),
            'role.assignments' => $this->putJson("/api/users/{$user->id}/roles", ['role_ids' => []]),
            'role.permissions' => $this->putJson("/api/roles/{$role->id}/permissions", ['permission_ids' => [$permission->id]]),
        };
        $response->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');

        foreach ($snapshot as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), "Changes to {$table} must roll back.");
        }
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertTrue($actor->exists);
    }

    public static function failingWrites(): array
    {
        return array_map(fn ($operation) => [$operation], [
            'patient.create', 'patient.update', 'patient.archive', 'appointment.create', 'appointment.update', 'appointment.status',
            'user.create', 'user.update', 'user.delete', 'user.password_reset', 'user.verify_email',
            'role.create', 'role.update', 'role.delete', 'role.assignments', 'role.permissions',
        ]);
    }

    public function test_outer_transaction_rollback_removes_success_audits_with_the_patient_mutation(): void
    {
        $actor = $this->actingAsAdmin();
        DB::beginTransaction();
        try {
            app(PatientWorkflow::class)->create(Patient::factory()->raw(), $actor);
            $this->assertDatabaseCount('patient', 1);
            $this->assertDatabaseCount('audit_logs', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('patient', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Admin', 'is_system' => true]);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function appointmentPayload(Appointment $appointment): array
    {
        return [
            'patient_id' => $appointment->patient_id, 'dentist_id' => $appointment->dentist_id,
            'appointment_date_time' => '2026-10-25 09:00:00', 'appointment_type' => 'Consultation',
            'description' => 'Private appointment details', 'duration_minutes' => 30,
        ];
    }
}
