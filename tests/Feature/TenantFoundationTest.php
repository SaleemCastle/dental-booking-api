<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chair;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Referral;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserWorkflow;
use App\Tenancy\TenantContext;
use Database\Seeders\AppointmentLocationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_clinic_seeding_preserves_identity_and_legacy_api_behavior(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(TenantSeeder::class);
        $this->assertDatabaseCount('tenants', 1);
        $tenant = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $admin = $this->clinicAdmin($tenant);
        Sanctum::actingAs($admin);
        $this->getJson('/api/user')->assertOk()->assertHeader('X-Tenant-Id', (string) $tenant->id)
            ->assertJsonPath('data.current_tenant_id', $tenant->id);
        $this->postJson('/api/users', ['name' => 'New member', 'email' => 'new-member@example.test', 'password' => 'Password-123'])
            ->assertCreated();
        $created = User::where('email', 'new-member@example.test')->sole();
        $this->assertTrue($created->tenants()->whereKey($tenant->id)->exists());
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_membership_discovery_does_not_require_membership_in_the_default_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/tenants')->assertOk()->assertJsonCount(1, 'data.tenants')
            ->assertJsonPath('data.tenants.0.id', $tenant->id)->assertJsonMissingPath('data.tenants.0.pivot');
        $this->getJson('/api/user')->assertForbidden();
        $this->withHeader('X-Tenant-Id', (string) $tenant->id)->getJson('/api/user')->assertOk();
    }

    public function test_clinic_headers_are_validated_and_membership_never_falls_back(): void
    {
        $foreign = Tenant::factory()->create();
        $user = User::factory()->create();
        $this->withHeader('X-Tenant-Id', (string) $foreign->id)->getJson('/api/patients')->assertUnauthorized();
        Sanctum::actingAs($user);
        foreach (['0', '-1', 'invalid', '1.5'] as $invalid) {
            $this->withHeader('X-Tenant-Id', $invalid)->getJson('/api/user')->assertUnprocessable();
        }
        foreach ([$foreign->id, 999999] as $tenantId) {
            $this->withHeader('X-Tenant-Id', (string) $tenantId)->getJson('/api/user')->assertForbidden();
        }
        Tenant::where('slug', Tenant::DEFAULT_SLUG)->update(['active' => false]);
        $this->withHeader('X-Tenant-Id', '1')->getJson('/api/user')->assertForbidden();
    }

    public function test_membership_revocation_blocks_a_reused_authenticated_user(): void
    {
        $tenant = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $admin = $this->clinicAdmin($tenant);
        Sanctum::actingAs($admin);
        $this->getJson('/api/user')->assertOk();
        $admin->tenants()->detach($tenant);
        $this->getJson('/api/user')->assertForbidden();
        $this->getJson('/api/tenants')->assertOk()->assertJsonCount(0, 'data.tenants');
        $this->assertFalse($admin->hasRole(Role::CLINIC_ADMIN));
        $this->assertSame([], $admin->permissionSlugs());
        $this->assertSame(0, $admin->roles()->count());
    }

    public function test_local_role_replacement_preserves_other_clinic_grants(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $foreign = Tenant::factory()->create();
        $user = $this->clinicAdmin($default);
        $user->tenants()->attach($foreign);
        $this->within($foreign, function () use ($user): void {
            $role = Role::create(['slug' => Role::DENTIST, 'name' => 'Dentist']);
            $user->roles()->attach($role);
        });
        Sanctum::actingAs($user);
        $this->putJson('/api/users/'.$user->id.'/roles', ['role_ids' => []])->assertOk();
        $this->assertFalse($user->hasRole(Role::CLINIC_ADMIN));
        $this->within($foreign, fn () => $this->assertTrue($user->hasRole(Role::DENTIST)));
        $this->assertSame(1, AuditLog::where('action', 'roles_changed')->count());
    }

    public function test_roles_permissions_and_cached_user_models_do_not_cross_clinics(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $foreign = Tenant::factory()->create();
        $user = $this->clinicAdmin($default);
        $user->tenants()->attach($foreign);
        $this->within($foreign, function () use ($user): void {
            $role = Role::create(['slug' => Role::RECEPTIONIST, 'name' => 'Receptionist']);
            $permission = Permission::where('slug', 'appointments.view')->sole();
            $role->permissions()->attach($permission);
            $user->roles()->attach($role);
            $this->assertFalse($user->hasRole(Role::CLINIC_ADMIN));
            $this->assertSame(['appointments.view'], $user->permissionSlugs());
        });
        $this->assertTrue($user->hasRole(Role::CLINIC_ADMIN));
        $this->assertContains('appointments.create', $user->permissionSlugs());
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-Id', (string) $foreign->id)->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/appointments', [])->assertForbidden();
        $this->withHeader('X-Tenant-Id', (string) $default->id)->getJson('/api/roles')->assertOk();
        $this->assertSame($default->id, app(TenantContext::class)->id());
    }

    public function test_all_clinical_detail_endpoints_and_user_lists_hide_foreign_records(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $foreign = Tenant::factory()->create();
        $admin = $this->clinicAdmin($default);
        $foreignIds = $this->within($foreign, function (): array {
            $patient = Patient::factory()->create();
            $dentist = Dentist::factory()->create();
            $treatment = Treatment::factory()->create();
            $appointment = Appointment::factory()->for($patient)->for($dentist)->for($treatment)->create();
            $invoice = Invoice::factory()->issued()->for($patient)->for($appointment)->create();
            $payment = Payment::factory()->for($invoice)->create();
            $note = ClinicalNote::factory()->for($patient)->for($dentist)->for($appointment)->create();
            $task = Task::factory()->for($patient)->for($appointment)->create();
            $message = Message::create(['sender_id' => $patient->id, 'receiver_id' => $patient->id, 'message_text' => 'Foreign message']);
            $referral = Referral::create(['patient_id' => $patient->id, 'dentist_id' => $dentist->id, 'referred_to_email' => 'external@example.test', 'referral_date' => '2026-11-01', 'referral_notes' => 'Foreign referral']);
            $user = User::factory()->create();
            $role = Role::create(['slug' => 'foreign', 'name' => 'Foreign']);
            $audit = app(AuditLogService::class)->record('created', 'patients', $patient->id, $user->id);

            return [
                'patients' => $patient->id, 'dentists' => $dentist->id, 'treatments' => $treatment->id,
                'appointments' => $appointment->id, 'invoices' => $invoice->id, 'payments' => $payment->id,
                'clinical-notes' => $note->id, 'tasks' => $task->id, 'users' => $user->id,
                'roles' => $role->id, 'audit-logs' => $audit->id,
                'messages' => $message->id, 'referrals' => $referral->id,
            ];
        });
        Sanctum::actingAs($admin);
        foreach ($foreignIds as $resource => $identifier) {
            $this->getJson('/api/'.$resource.'/'.$identifier)->assertNotFound();
        }
        $this->getJson('/api/users')->assertOk()->assertJsonCount(1, 'data.users');
        $this->getJson('/api/patients')->assertOk()->assertJsonCount(0, 'data.patients');
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(0, 'data.appointments');
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.bookedToday', 0);
        $this->getJson('/api/audit-logs')->assertOk()->assertJsonCount(0, 'data.auditLogs');
    }

    public function test_cross_clinic_relationship_ids_and_body_ownership_are_rejected(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $foreign = Tenant::factory()->create();
        $admin = $this->clinicAdmin($default);
        [$patient, $provider, $assignee, $role] = $this->within($foreign, fn () => [
            Patient::factory()->create(), Dentist::factory()->create(), User::factory()->create(),
            Role::create(['name' => 'Foreign', 'slug' => 'foreign']),
        ]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'dentist_id' => $provider->id,
            'appointment_date_time' => now()->addDay()->toISOString(), 'appointment_type' => 'Checkup', 'description' => 'Test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['patient_id', 'dentist_id']);
        $this->postJson('/api/tasks', ['assignee_id' => $assignee->id, 'due_date' => '2026-11-01', 'description' => 'Test'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->postJson('/api/messages', ['sender_id' => $patient->id, 'receiver_id' => $patient->id, 'message_text' => 'Test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sender_id', 'receiver_id']);
        $this->postJson('/api/invoices', ['patient_id' => $patient->id, 'invoice_number' => 'INV-test', 'amount_due' => 10, 'status' => 'unpaid'])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->postJson('/api/clinical-notes', ['patient_id' => $patient->id, 'dentist_id' => $provider->id, 'note_text' => 'Test'])
            ->assertUnprocessable()->assertJsonValidationErrors(['patient_id', 'dentist_id']);
        $this->putJson('/api/users/'.$admin->id.'/roles', ['role_ids' => [$role->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
        $this->postJson('/api/patients', ['tenant_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->assertSame(0, AuditLog::count());
    }

    public function test_same_role_slugs_and_room_names_are_valid_in_different_clinics(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(AppointmentLocationSeeder::class);
        $this->seed(PermissionSeeder::class);
        $permissionCount = Permission::count();
        $tenant = Tenant::factory()->create();
        $this->within($tenant, function () use ($permissionCount): void {
            $this->seed(RoleSeeder::class);
            $this->seed(PermissionSeeder::class);
            $this->seed(AppointmentLocationSeeder::class);
            $this->assertSame(6, Role::count());
            $this->assertSame(2, Chair::count());
            $this->assertSame($permissionCount, Role::where('slug', Role::ADMIN)->sole()->permissions()->count());
        });
        $this->assertSame(6, Role::count());
        $this->assertSame(2, Chair::count());
        $this->assertSame($permissionCount, Permission::count());
    }

    public function test_stale_models_cannot_be_written_in_another_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $patient = $this->within($tenant, fn () => Patient::factory()->create());
        $this->expectException(ValidationException::class);
        $patient->update(['notes' => 'Cross-clinic write']);
    }

    public function test_policy_checks_deny_foreign_instances_even_for_a_clinic_admin(): void
    {
        $admin = $this->clinicAdmin(Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole());
        $tenant = Tenant::factory()->create();
        $patient = $this->within($tenant, fn () => Patient::factory()->create());
        $this->assertFalse(Gate::forUser($admin)->allows('view', $patient));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $patient));
    }

    public function test_database_rejects_cross_clinic_role_pivot_assignments(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $role = $this->within($tenant, fn () => Role::create(['name' => 'Foreign', 'slug' => 'foreign']));
        $this->expectException(QueryException::class);
        $user->roles()->attach($role);
    }

    public function test_shared_identity_is_not_deleted_or_editable_by_another_clinic_member(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $foreign = Tenant::factory()->create();
        $admin = $this->clinicAdmin($default);
        $shared = $this->clinicAdmin($foreign);
        $shared->tenants()->attach($default);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/users/'.$shared->id, ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson('/api/users/'.$shared->id)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $shared->id]);
        $this->assertFalse($shared->tenants()->whereKey($default->id)->exists());
        $this->within($foreign, fn () => $this->assertTrue($shared->hasRole(Role::CLINIC_ADMIN)));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $default->id, 'action' => 'membership_removed', 'resource_id' => (string) $shared->id]);
    }

    public function test_identity_audits_follow_memberships_instead_of_leaking_into_default_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        app(UserWorkflow::class)->resetPassword($user, 'Changed-test-password');
        $this->assertSame(0, AuditLog::count());
        $this->within($tenant, function () use ($user): void {
            $entry = AuditLog::sole();
            $this->assertSame('password_reset', $entry->action);
            $this->assertSame($user->id, $entry->actor_id);
        });
    }

    private function clinicAdmin(Tenant $tenant): User
    {
        return $this->within($tenant, function (): User {
            $this->seed(RoleSeeder::class);
            $this->seed(PermissionSeeder::class);
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('slug', Role::CLINIC_ADMIN)->sole());

            return $user;
        });
    }

    private function within(Tenant $tenant, \Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }
}
