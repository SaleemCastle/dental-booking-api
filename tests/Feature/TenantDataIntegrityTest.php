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
use App\Models\Referral;
use App\Models\Role;
use App\Models\Room;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('relationshipCases')]
    public function test_bulk_writes_cannot_link_clinics(string $model, string $column, string $parentModel): void
    {
        $record = match ($model) {
            Message::class => Message::create(['sender_id' => Patient::factory()->create()->id, 'receiver_id' => Patient::factory()->create()->id, 'message_text' => 'Message']),
            Referral::class => Referral::create(['patient_id' => Patient::factory()->create()->id, 'dentist_id' => Dentist::factory()->create()->id, 'referred_to_email' => 'external@example.test', 'referral_date' => '2026-11-01', 'referral_notes' => 'Referral']),
            default => $model::factory()->create(),
        };
        $foreign = Tenant::factory()->create();
        $parent = app(TenantContext::class)->run($foreign, fn () => $parentModel::factory()->create());
        $original = DB::table($record->getTable())->where('id', $record->id)->first();

        foreach (['update', 'insert'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table($record->getTable())->where('id', $record->id)->update([$column => $parent->id]);
                } else {
                    $attributes = (array) $original;
                    unset($attributes['id']);
                    $attributes[$column] = $parent->id;
                    DB::table($record->getTable())->insert($attributes);
                }
                $this->fail('Cross-clinic '.$operation.' succeeded.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Tenant integrity violation', $exception->getMessage());
            }
        }
        $this->assertEquals($original, DB::table($record->getTable())->where('id', $record->id)->first());
    }

    public static function relationshipCases(): array
    {
        return [
            [Appointment::class, 'patient_id', Patient::class],
            [Appointment::class, 'dentist_id', Dentist::class],
            [Appointment::class, 'treatment_id', Treatment::class],
            [Appointment::class, 'room_id', Room::class],
            [Appointment::class, 'chair_id', Chair::class],
            [Chair::class, 'room_id', Room::class],
            [Invoice::class, 'patient_id', Patient::class],
            [Invoice::class, 'appointment_id', Appointment::class],
            [Payment::class, 'invoice_id', Invoice::class],
            [Message::class, 'sender_id', Patient::class],
            [Message::class, 'receiver_id', Patient::class],
            [Referral::class, 'patient_id', Patient::class],
            [Referral::class, 'dentist_id', Dentist::class],
            [ClinicalNote::class, 'patient_id', Patient::class],
            [ClinicalNote::class, 'dentist_id', Dentist::class],
            [ClinicalNote::class, 'appointment_id', Appointment::class],
            [Task::class, 'patient_id', Patient::class],
            [Task::class, 'appointment_id', Appointment::class],
            [Task::class, 'assignee_id', User::class],
        ];
    }

    public function test_bulk_ownership_transfers_and_unknown_clinics_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        foreach ([Patient::factory()->create(), Treatment::factory()->create(), Role::create(['slug' => 'local', 'name' => 'Local']),
            app(AuditLogService::class)->record('created', 'patients', 1, null)] as $record) {
            try {
                DB::table($record->getTable())->where('id', $record->id)->update(['tenant_id' => $tenant->id]);
                $this->fail('Ownership transfer succeeded.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Tenant integrity violation', $exception->getMessage());
            }
        }
        $attributes = Patient::firstOrFail()->getAttributes();
        unset($attributes['id']);
        $attributes['tenant_id'] = 999999;
        $this->expectException(QueryException::class);
        DB::table('patient')->insert($attributes);
    }

    #[DataProvider('legacyOrphanCases')]
    public function test_legacy_links_prevent_parent_deletion_and_key_replacement(string $child, string $column, string $parent): void
    {
        $record = $child === 'appointment' ? Appointment::factory()->create() : Referral::create([
            'patient_id' => Patient::factory()->create()->id, 'dentist_id' => Dentist::factory()->create()->id,
            'referred_to_email' => 'external@example.test', 'referral_date' => '2026-11-01', 'referral_notes' => 'Referral',
        ]);
        $parentId = $record->getAttribute($column);
        foreach (['delete', 'replace'] as $operation) {
            try {
                $query = DB::table($parent)->where('id', $parentId);
                $operation === 'delete' ? $query->delete() : $query->update(['id' => 999999]);
                $this->fail('A referenced parent was removed.');
            } catch (QueryException) {
                $this->assertDatabaseHas($parent, ['id' => $parentId]);
                $this->assertDatabaseHas($child, ['id' => $record->id, $column => $parentId]);
            }
        }
    }

    public static function legacyOrphanCases(): array
    {
        return [
            ['appointment', 'patient_id', 'patient'], ['appointment', 'dentist_id', 'dentist'],
            ['referral', 'patient_id', 'patient'], ['referral', 'dentist_id', 'dentist'],
        ];
    }

    public function test_clinics_with_owned_data_cannot_be_deleted_or_have_their_key_replaced(): void
    {
        $tenant = Tenant::factory()->create();
        $patient = app(TenantContext::class)->run($tenant, fn () => Patient::factory()->create());
        foreach (['delete', 'replace'] as $operation) {
            try {
                $query = DB::table('tenants')->where('id', $tenant->id);
                $operation === 'delete' ? $query->delete() : $query->update(['id' => 999999]);
                $this->fail('A clinic with clinical data was removed.');
            } catch (QueryException) {
                $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
                $this->assertDatabaseHas('patient', ['id' => $patient->id, 'tenant_id' => $tenant->id]);
            }
        }
    }

    public function test_membership_removal_requires_task_reassignment_and_is_atomic(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $member->roles()->attach(Role::firstOrFail());
        $task = Task::factory()->create(['assignee_id' => $member->id]);
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/users/'.$member->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertTrue($member->isMemberOfCurrentTenant());
        $this->assertSame(1, $member->roles()->count());
        $this->assertSame(0, AuditLog::count());
        try {
            $member->tenants()->detach(app(TenantContext::class)->id());
            $this->fail('Assigned membership was removed.');
        } catch (QueryException) {
            $this->assertTrue($member->isMemberOfCurrentTenant());
        }
        $task->update(['assignee_id' => $admin->id]);
        $this->deleteJson('/api/users/'.$member->id)->assertOk();
        $this->assertSame($admin->id, $task->refresh()->assignee_id);
    }

    public function test_new_attribution_requires_membership_but_historical_authorship_survives_departure(): void
    {
        $note = ClinicalNote::factory()->create();
        $author = User::findOrFail($note->author_user_id);
        $author->tenants()->detach(app(TenantContext::class)->id());
        $note->update(['note_text' => 'Historical correction']);
        $this->assertSame($author->id, $note->refresh()->author_user_id);
        $foreign = Tenant::factory()->create();
        $outsider = User::factory()->forTenant($foreign)->create();
        $this->expectException(ValidationException::class);
        $note->forceFill(['updated_by_user_id' => $outsider->id])->save();
    }

    #[DataProvider('attributionCases')]
    public function test_bulk_writes_cannot_attribute_clinical_changes_to_foreign_members(string $model, string $column): void
    {
        $record = $model::factory()->create();
        $tenant = Tenant::factory()->create();
        $outsider = User::factory()->forTenant($tenant)->create();
        foreach (['insert', 'update'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table($record->getTable())->where('id', $record->id)->update([$column => $outsider->id]);
                } else {
                    $attributes = $record->getAttributes();
                    unset($attributes['id']);
                    $attributes[$column] = $outsider->id;
                    DB::table($record->getTable())->insert($attributes);
                }
                $this->fail('Foreign clinic attribution succeeded.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Tenant integrity violation', $exception->getMessage());
            }
        }
    }

    public static function attributionCases(): array
    {
        return [
            [ClinicalNote::class, 'author_user_id'], [ClinicalNote::class, 'updated_by_user_id'],
            [ClinicalNote::class, 'deleted_by_user_id'], [Patient::class, 'archived_by_user_id'],
        ];
    }

    public function test_tasks_in_another_clinic_do_not_prevent_local_membership_removal(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $member->tenants()->attach($tenant);
        $task = app(TenantContext::class)->run($tenant, fn () => Task::factory()->create(['assignee_id' => $member->id]));
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/users/'.$member->id)->assertOk();
        $this->assertFalse($member->isMemberOfCurrentTenant());
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assignee_id' => $member->id, 'tenant_id' => $tenant->id]);
    }

    public function test_nullable_links_keep_phase_one_delete_behavior(): void
    {
        $appointment = Appointment::factory()->create();
        $invoice = Invoice::factory()->for($appointment)->create();
        $note = ClinicalNote::factory()->for($appointment)->create();
        $task = Task::factory()->for($appointment)->create();
        $tenantId = $appointment->tenant_id;
        $appointment->delete();
        foreach ([$invoice, $note, $task] as $record) {
            $this->assertNull($record->refresh()->appointment_id);
            $this->assertSame($tenantId, $record->tenant_id);
        }
    }

    public function test_invoice_numbers_are_unique_per_clinic(): void
    {
        Invoice::factory()->create(['invoice_number' => 'INV-001']);
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->run($tenant, function (): void {
            Invoice::factory()->create(['invoice_number' => 'INV-001']);
            $this->assertSame(1, Invoice::where('invoice_number', 'INV-001')->count());
        });
        $this->assertSame(1, Invoice::where('invoice_number', 'INV-001')->count());
        $this->expectException(QueryException::class);
        Invoice::factory()->create(['invoice_number' => 'INV-001']);
    }

    public function test_phase_one_api_workflows_stamp_selected_clinic_and_scope_search_reports_and_audits(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(8));
        $defaultAdmin = $this->admin();
        Patient::factory()->create(['firstName' => 'Shared', 'lastName' => 'Search']);
        Invoice::factory()->create(['amount_due' => 9000, 'amount_paid' => 9000, 'status' => 'paid']);
        $tenant = Tenant::factory()->create();
        [$admin, $dentist] = app(TenantContext::class)->run($tenant, fn () => [$this->admin(), Dentist::factory()->create()]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-Tenant-Id', (string) $tenant->id);
        $patientId = $this->postJson('/api/patients', Patient::factory()->make(['firstName' => 'Shared', 'lastName' => 'Search'])->attributesToArray())
            ->assertCreated()->json('data.patient.id');
        $treatmentId = $this->postJson('/api/treatments', ['name' => 'Review', 'category' => 'General', 'duration_minutes' => 30, 'price' => 100])
            ->assertCreated()->json('data.treatment.id');
        $appointmentId = $this->postJson('/api/appointments', [
            'patient_id' => $patientId, 'dentist_id' => $dentist->id, 'treatment_id' => $treatmentId,
            'appointment_date_time' => now()->addHours(2)->toISOString(), 'appointment_type' => 'Review', 'description' => 'Review',
        ])->assertCreated()->json('data.appointment.id');
        $noteId = $this->postJson('/api/clinical-notes', [
            'patient_id' => $patientId, 'dentist_id' => $dentist->id, 'appointment_id' => $appointmentId, 'note_text' => 'Findings',
        ])->assertCreated()->json('data.clinicalNote.id');
        $taskId = $this->postJson('/api/tasks', [
            'appointment_id' => $appointmentId, 'assignee_id' => $admin->id, 'due_date' => today()->toDateString(), 'description' => 'Follow up',
        ])->assertCreated()->json('data.task.id');
        $invoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $patientId, 'appointment_id' => $appointmentId, 'invoice_number' => 'INV-shared', 'amount_due' => 100, 'amount_paid' => 25, 'status' => 'partial',
        ])->assertCreated()->json('data.invoice.id');
        foreach (['patient' => $patientId, 'treatments' => $treatmentId, 'appointment' => $appointmentId, 'clinical_notes' => $noteId, 'tasks' => $taskId, 'invoices' => $invoiceId] as $table => $id) {
            $this->assertDatabaseHas($table, ['id' => $id, 'tenant_id' => $tenant->id]);
        }
        $this->patchJson('/api/clinical-notes/'.$noteId, ['note_text' => 'Updated findings'])->assertOk();
        $this->patchJson('/api/tasks/'.$taskId.'/status', ['status' => 'completed'])->assertOk();
        $this->getJson('/api/patients?search=Shared+Search')->assertOk()->assertJsonCount(1, 'data.patients')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.bookedToday', 1)
            ->assertJsonPath('data.unpaidInvoiceAmount', 75)->assertJsonPath('data.collectionRate', 25);
        $this->getJson('/api/tasks?due_today=1')->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson('/api/audit-logs?resource_type=clinical_notes')->assertOk()->assertJsonCount(2, 'data.auditLogs');
        $this->deleteJson('/api/patients/'.$patientId)->assertOk();
        $this->getJson('/api/patients?search=Shared&archived=only')->assertOk()->assertJsonCount(1, 'data.patients');
        $this->assertSame(0, AuditLog::where('actor_id', $admin->id)->count());
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('actor_id', $admin->id)->count());
        Sanctum::actingAs($defaultAdmin);
        $this->withHeader('X-Tenant-Id', (string) app(TenantContext::class)->id());
        $this->getJson('/api/patients?search=Shared')->assertOk()->assertJsonCount(1, 'data.patients');
        $this->getJson('/api/clinical-notes/'.$noteId)->assertNotFound();
        $this->getJson('/api/audit-logs?resource_type=clinical_notes')->assertOk()->assertJsonCount(0, 'data.auditLogs');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Admin']);
        $user->roles()->attach($role);

        return $user;
    }
}
