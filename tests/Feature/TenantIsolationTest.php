<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chair;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AppointmentWorkflow;
use App\Services\AuditLogService;
use App\Services\ClinicalNoteWorkflow;
use App\Services\PatientWorkflow;
use App\Services\RoleWorkflow;
use App\Services\TaskWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $local;

    private array $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->local = $this->clinic(Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole(), 'Local');
        $this->foreign = $this->clinic(Tenant::factory()->create(), 'ForeignSecret');
        Sanctum::actingAs($this->local['user']);
        $this->withHeader('X-Tenant-Id', (string) $this->local['tenant']->id);
    }

    public function test_gate_rejects_an_inactive_clinic_even_with_existing_admin_and_wildcard_grants(): void
    {
        $this->local['tenant']->update(['active' => false]);
        foreach ([Patient::class, Appointment::class, Treatment::class, ClinicalNote::class, Task::class, AuditLog::class] as $model) {
            $this->assertFalse(Gate::forUser($this->local['user'])->allows('viewAny', $model), $model);
        }
    }

    #[DataProvider('knownIdAttacks')]
    public function test_known_foreign_routes_are_indistinguishable_from_missing_records(string $method, string $path, array $payload = []): void
    {
        $before = $this->snapshot();
        $known = $this->json($method, $this->path($path, $this->foreign), $payload)
            ->assertNotFound()->assertJsonPath('data', null)->assertJsonPath('code', 'NOT_FOUND')
            ->assertDontSee('ForeignSecret');
        $missing = $this->json($method, preg_replace('/\{\w+\}/', '999999', $path), $payload)
            ->assertNotFound()->assertJsonPath('data', null);
        $this->assertSame($this->body($missing), $this->body($known));
        $this->assertSame($before, $this->snapshot(), 'Denied requests must not change records, memberships, grants, or audits.');
    }

    public static function knownIdAttacks(): array
    {
        return [
            ['GET', '/api/patients/{patient}'],
            ['PATCH', '/api/patients/{patient}', ['notes' => 'Changed']],
            ['PUT', '/api/patients/{patient}', ['allergies' => ['Changed']]],
            ['PUT', '/api/patients/{patient}/update', ['notes' => 'Changed']],
            ['DELETE', '/api/patients/{patient}'],
            ['DELETE', '/api/patients/{patient}/delete'],
            ['GET', '/api/appointments/{appointment}'],
            ['PATCH', '/api/appointments/{appointment}', ['description' => 'Changed']],
            ['PUT', '/api/appointments/{appointment}', ['status' => 'cancelled']],
            ['DELETE', '/api/appointments/{appointment}'],
            ['POST', '/api/appointments/{appointment}/check-in'],
            ['POST', '/api/appointments/{appointment}/complete'],
            ['POST', '/api/appointments/{appointment}/cancel', ['reason' => 'Changed']],
            ['GET', '/api/treatments/{treatment}'],
            ['PATCH', '/api/treatments/{treatment}', ['price' => 1]],
            ['PUT', '/api/treatments/{treatment}', ['active' => false]],
            ['DELETE', '/api/treatments/{treatment}'],
            ['GET', '/api/clinical-notes/{note}'],
            ['PATCH', '/api/clinical-notes/{note}', ['note_text' => 'Changed']],
            ['PUT', '/api/clinical-notes/{note}', ['note_text' => 'Changed']],
            ['DELETE', '/api/clinical-notes/{note}'],
            ['GET', '/api/tasks/{task}'],
            ['PATCH', '/api/tasks/{task}', ['description' => 'Changed']],
            ['PUT', '/api/tasks/{task}', ['status' => 'cancelled']],
            ['PATCH', '/api/tasks/{task}/status', ['status' => 'completed']],
            ['DELETE', '/api/tasks/{task}'],
            ['GET', '/api/audit-logs/{audit}'],
            ['GET', '/api/roles/{role}'],
            ['PATCH', '/api/roles/{role}', ['name' => 'Changed']],
            ['DELETE', '/api/roles/{role}'],
            ['PUT', '/api/roles/{role}/permissions', ['permission_ids' => []]],
            ['PUT', '/api/users/{user}/roles', ['role_ids' => []]],
        ];
    }

    #[DataProvider('relationshipAttacks')]
    public function test_create_and_update_validation_do_not_reveal_foreign_relationship_existence(string $resource, string $target, string $field, string $related, ?string $validationField = null): void
    {
        $validationField ??= $field;
        $before = $this->snapshot();
        foreach (['POST' => '/api/'.$resource, 'PATCH' => '/api/'.$resource.'/'.$this->local[$target]->id] as $method => $path) {
            $payload = $this->payload($resource, $this->local);
            unset($payload[$validationField]);
            $payload[$field] = $this->foreign[$related]->id;
            $foreign = $this->json($method, $path, $payload)->assertUnprocessable()
                ->assertJsonValidationErrors($validationField)->assertJsonPath('data', null)->assertDontSee('ForeignSecret');
            $payload[$field] = 999999;
            $missing = $this->json($method, $path, $payload)->assertUnprocessable()->assertJsonValidationErrors($validationField);
            $this->assertSame($this->body($missing), $this->body($foreign));
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function relationshipAttacks(): array
    {
        return [
            ['appointments', 'appointment', 'patient_id', 'patient'],
            ['appointments', 'appointment', 'dentist_id', 'dentist'],
            ['appointments', 'appointment', 'treatment_id', 'treatment'],
            ['appointments', 'appointment', 'room_id', 'room'],
            ['appointments', 'appointment', 'chair_id', 'chair'],
            ['appointments', 'appointment', 'patientId', 'patient', 'patient_id'],
            ['appointments', 'appointment', 'dentistId', 'dentist', 'dentist_id'],
            ['appointments', 'appointment', 'treatmentId', 'treatment', 'treatment_id'],
            ['appointments', 'appointment', 'roomId', 'room', 'room_id'],
            ['appointments', 'appointment', 'chairId', 'chair', 'chair_id'],
            ['clinical-notes', 'note', 'patient_id', 'patient'],
            ['clinical-notes', 'note', 'dentist_id', 'dentist'],
            ['clinical-notes', 'note', 'appointment_id', 'appointment'],
            ['tasks', 'task', 'patient_id', 'patient'],
            ['tasks', 'task', 'appointment_id', 'appointment'],
            ['tasks', 'task', 'assignee_id', 'user'],
            ['invoices', 'invoice', 'patient_id', 'patient'],
            ['invoices', 'invoice', 'appointment_id', 'appointment'],
        ];
    }

    public function test_foreign_headers_cannot_authorize_reads_mutations_or_role_assignment(): void
    {
        $before = $this->snapshot();
        foreach ([
            ['GET', '/api/patients'], ['GET', '/api/dashboard/summary'], ['GET', '/api/appointment-locations'],
            ['GET', '/api/audit-logs'], ['GET', $this->path('/api/patients/{patient}', $this->foreign)],
            ['PATCH', $this->path('/api/tasks/{task}/status', $this->foreign)],
            ['DELETE', $this->path('/api/patients/{patient}', $this->foreign)],
            ['POST', '/api/appointments'], ['PUT', $this->path('/api/users/{user}/roles', $this->foreign)],
        ] as [$method, $path]) {
            $expected = null;
            foreach ([$this->foreign['tenant']->id, 999999] as $tenantId) {
                $response = $this->json($method, $path, [], ['X-Tenant-Id' => (string) $tenantId])
                    ->assertForbidden()->assertJsonPath('data', null)->assertDontSee('ForeignSecret');
                $expected ??= $this->body($response);
                $this->assertSame($expected, $this->body($response));
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_list_search_selectors_and_pagination_are_scoped_in_both_directions(): void
    {
        foreach ([$this->local, $this->foreign] as $clinic) {
            Sanctum::actingAs($clinic['user']);
            $this->withHeader('X-Tenant-Id', (string) $clinic['tenant']->id);
            foreach ([
                'patients' => ['patients', 'patient'], 'appointments' => ['appointments', 'appointment'],
                'treatments' => ['treatments', 'treatment'], 'clinical-notes' => ['clinicalNotes', 'note'],
                'tasks' => ['tasks', 'task'], 'audit-logs' => ['auditLogs', 'audit'],
                'users' => ['users', 'user'], 'roles' => ['roles', 'role'],
            ] as $resource => [$key, $record]) {
                $response = $this->getJson('/api/'.$resource)->assertOk();
                $this->assertSame([$clinic[$record]->id], array_column($response->json('data.'.$key), 'id'), $resource);
                if ($response->json('meta.pagination') !== null) {
                    $response->assertJsonPath('meta.pagination.total', 1);
                    $this->getJson('/api/'.$resource.'?per_page=1&page=2')->assertOk()
                        ->assertJsonCount(0, 'data.'.$key)->assertJsonPath('meta.pagination.total', 1);
                }
            }
            foreach (['Shared', 'Shared Patient', (string) $clinic['patient']->id] as $search) {
                $this->getJson('/api/patients?search='.urlencode($search))->assertOk()
                    ->assertJsonPath('data.patients.0.id', $clinic['patient']->id)->assertJsonPath('meta.pagination.total', 1);
            }
            $this->getJson('/api/treatments?active=1')->assertOk()->assertJsonCount(1, 'data.treatments')
                ->assertJsonPath('data.treatments.0.id', $clinic['treatment']->id);
            $this->getJson('/api/appointment-locations')->assertOk()->assertJsonCount(1, 'data.rooms')
                ->assertJsonPath('data.rooms.0.id', $clinic['room']->id)
                ->assertJsonCount(1, 'data.rooms.0.chairs')->assertJsonPath('data.rooms.0.chairs.0.id', $clinic['chair']->id);
            $this->getJson('/api/tasks?assigned_to_me=1&due_today=1&status=pending')->assertOk()
                ->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.id', $clinic['task']->id);
        }
    }

    public function test_foreign_archives_deleted_notes_and_numeric_or_text_searches_are_hidden(): void
    {
        app(TenantContext::class)->run($this->foreign['tenant'], function (): void {
            $this->foreign['patient']->archive($this->foreign['user']);
            $this->foreign['note']->delete();
        });
        foreach (['ForeignSecret', (string) $this->foreign['patient']->id] as $search) {
            foreach (['without', 'with', 'only'] as $archived) {
                $response = $this->getJson('/api/patients?'.http_build_query(['search' => $search, 'archived' => $archived]))->assertOk();
                // Numeric searches also match contact/address fields on local patients.
                $this->assertNotContains($this->foreign['patient']->id, array_column($response->json('data.patients'), 'id'));
                if ($search === 'ForeignSecret' || $archived === 'only') {
                    $response->assertJsonCount(0, 'data.patients')->assertJsonPath('meta.pagination.total', 0);
                }
            }
        }
        $this->getJson('/api/patients?archived=with')->assertOk()->assertJsonCount(1, 'data.patients');
        $this->getJson('/api/clinical-notes')->assertOk()->assertJsonCount(1, 'data.clinicalNotes');
        $this->assertNull(ClinicalNote::withTrashed()->find($this->foreign['note']->id));
    }

    public function test_foreign_filter_ids_produce_the_same_validation_errors_as_absent_ids(): void
    {
        foreach ([
            ['/api/tasks', 'patient_id', 'patient'],
            ['/api/clinical-notes', 'patient_id', 'patient'],
            ['/api/clinical-notes', 'dentist_id', 'dentist'],
            ['/api/clinical-notes', 'appointment_id', 'appointment'],
        ] as [$path, $field, $related]) {
            $foreign = $this->getJson($path.'?'.$field.'='.$this->foreign[$related]->id)->assertUnprocessable()->assertDontSee('ForeignSecret');
            $missing = $this->getJson($path.'?'.$field.'=999999')->assertUnprocessable();
            $this->assertSame($this->body($missing), $this->body($foreign));
        }
    }

    public function test_create_payload_ids_cannot_overwrite_foreign_records_or_choose_ownership(): void
    {
        $before = $this->foreignSnapshot();
        foreach (['patients' => ['patient', 'patient'], 'treatments' => ['treatment', 'treatments'],
            'appointments' => ['appointment', 'appointment'], 'clinical-notes' => ['note', 'clinical_notes'], 'tasks' => ['task', 'tasks']] as $resource => [$target, $table]) {
            $payload = match ($resource) {
                'patients' => Patient::factory()->make()->attributesToArray(),
                'treatments' => ['name' => 'Shared Treatment', 'category' => 'General', 'price' => 50, 'duration_minutes' => 30],
                default => $this->payload($resource, $this->local),
            };
            $payload['id'] = $this->foreign[$target]->id;
            foreach (['tenant_id', 'tenantId'] as $field) {
                $this->postJson('/api/'.$resource, [...$payload, $field => $this->foreign['tenant']->id])
                    ->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
            }
            $key = $resource === 'clinical-notes' ? 'clinicalNote' : $target;
            $created = $this->postJson('/api/'.$resource, $payload)->assertCreated()->json('data.'.$key.'.id');
            $this->assertNotSame($this->foreign[$target]->id, $created);
            $this->assertDatabaseHas($table, ['id' => $created, 'tenant_id' => $this->local['tenant']->id]);
        }
        $this->assertSame($before, $this->foreignSnapshot());
    }

    public function test_local_wildcard_roles_do_not_follow_a_shared_identity_into_another_clinic(): void
    {
        $user = $this->local['user'];
        $user->tenants()->attach($this->foreign['tenant']);
        $user->load('roles.permissions');
        $before = $this->snapshot();
        $this->withHeader('X-Tenant-Id', (string) $this->foreign['tenant']->id);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.roles', [])->assertJsonPath('data.permissions', []);
        foreach (['patients', 'appointments', 'treatments', 'clinical-notes', 'tasks', 'audit-logs', 'roles'] as $resource) {
            $this->getJson('/api/'.$resource)->assertForbidden()->assertJsonPath('data', null);
        }
        $this->getJson('/api/patients/'.$this->foreign['patient']->id)->assertForbidden()->assertDontSee('ForeignSecret');
        $this->postJson('/api/appointments/'.$this->foreign['appointment']->id.'/check-in')->assertForbidden();
        $this->patchJson('/api/tasks/'.$this->foreign['task']->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->putJson('/api/users/'.$this->foreign['user']->id.'/roles', ['role_ids' => [$this->local['role']->id]])->assertForbidden();
        $this->withHeader('X-Tenant-Id', (string) $this->local['tenant']->id)->getJson('/api/patients')
            ->assertOk()->assertJsonPath('data.patients.0.id', $this->local['patient']->id);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_tenant_owned_queries_are_scoped_without_any_frontend_filter(): void
    {
        foreach (['patient', 'appointment', 'treatment', 'note', 'task', 'audit', 'dentist', 'room', 'chair', 'invoice', 'role'] as $key) {
            $model = $this->local[$key]::class;
            $this->assertSame(1, $model::count(), $model);
            $this->assertNull($model::find($this->foreign[$key]->id), $model);
            $this->assertSame([$this->local[$key]->id], $model::query()->orWhere('id', $this->foreign[$key]->id)
                ->orWhere('id', $this->local[$key]->id)->pluck('id')->all(), $model);
        }
    }

    public function test_unique_validation_does_not_report_values_owned_only_by_another_clinic(): void
    {
        app(TenantContext::class)->run($this->foreign['tenant'], function (): void {
            Role::create(['slug' => 'private-slug', 'name' => 'ForeignSecret role']);
            $this->foreign['dentist']->update(['email' => 'private-provider@example.test']);
            $this->foreign['invoice']->update(['invoice_number' => 'INV-FOREIGN']);
        });
        $before = $this->foreignSnapshot();
        foreach ([
            ['/api/roles', ['name' => 'Local role', 'slug' => 'private-slug'], 'slug'],
            ['/api/dentists', ['first_name' => 'Local', 'last_name' => 'Provider', 'email' => 'private-provider@example.test', 'contact_number' => '12345'], 'email'],
            ['/api/invoices', [...$this->payload('invoices', $this->local), 'invoice_number' => 'INV-FOREIGN'], 'invoice_number'],
        ] as [$path, $payload, $field]) {
            $this->postJson($path, $payload)->assertCreated()->assertDontSee('ForeignSecret');
            $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors($field)->assertDontSee('ForeignSecret');
        }
        $this->assertSame($before, $this->foreignSnapshot());
    }

    public function test_dashboard_financial_totals_and_staff_counts_exclude_foreign_activity(): void
    {
        app(TenantContext::class)->run($this->foreign['tenant'], function (): void {
            foreach (['checked_in', 'completed'] as $status) {
                Appointment::factory()->create([
                    'patient_id' => $this->foreign['patient']->id, 'dentist_id' => $this->foreign['dentist']->id,
                    'treatment_id' => $this->foreign['treatment']->id, 'status' => $status,
                    'appointment_date_time' => today()->addHours(12),
                ]);
            }
            $this->foreign['invoice']->update(['amount_due' => 9000, 'amount_paid' => 3000]);
        });
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.bookedToday', 1)
            ->assertJsonPath('data.waitingRoom', 0)->assertJsonPath('data.treatmentsDone', 0)
            ->assertJsonPath('data.unpaidInvoiceAmount', 75)->assertJsonPath('data.collectionRate', 25)
            ->assertJsonCount(1, 'data.todayAppointments')->assertJsonPath('data.todayAppointments.0.id', $this->local['appointment']->id)
            ->assertJsonCount(1, 'data.staffWorklist')->assertJsonPath('data.staffWorklist.0.dentistId', $this->local['dentist']->id)
            ->assertJsonPath('data.staffWorklist.0.scheduled', 1)->assertJsonPath('data.staffWorklist.0.checkedIn', 0)
            ->assertJsonPath('data.staffWorklist.0.completed', 0)->assertDontSee('ForeignSecret');
        Sanctum::actingAs($this->foreign['user']);
        $this->withHeader('X-Tenant-Id', (string) $this->foreign['tenant']->id)->getJson('/api/dashboard/summary')
            ->assertOk()->assertJsonPath('data.bookedToday', 3)->assertJsonPath('data.waitingRoom', 1)
            ->assertJsonPath('data.treatmentsDone', 1)->assertJsonPath('data.unpaidInvoiceAmount', 6000)
            ->assertJsonPath('data.collectionRate', 33.33)->assertJsonCount(1, 'data.staffWorklist');
    }

    public function test_audit_filters_metadata_and_counts_never_disclose_foreign_history(): void
    {
        app(TenantContext::class)->run($this->foreign['tenant'], fn () => app(AuditLogService::class)
            ->record('foreign_action', 'foreign_resource', 'overlap', $this->foreign['user']->id));
        $this->getJson('/api/audit-logs/filters')->assertOk()->assertJsonPath('data.actions', ['updated'])
            ->assertJsonPath('data.resourceTypes', ['patients']);
        foreach ([
            ['actor_id' => $this->foreign['user']->id], ['action' => 'foreign_action'],
            ['resource_type' => 'foreign_resource'], ['request_id' => 'foreign-correlation'],
        ] as $filter) {
            $this->getJson('/api/audit-logs?'.http_build_query($filter))->assertOk()
                ->assertJsonCount(0, 'data.auditLogs')->assertJsonPath('meta.pagination.total', 0)->assertDontSee('ForeignSecret');
        }
        $this->getJson('/api/audit-logs?resource_type=patients&resource_id=overlap')->assertOk()
            ->assertJsonCount(1, 'data.auditLogs')->assertJsonPath('data.auditLogs.0.id', $this->local['audit']->id)
            ->assertJsonPath('data.auditLogs.0.actor_id', $this->local['user']->id)->assertJsonPath('meta.pagination.total', 1);
        foreach (['POST' => '/api/audit-logs', 'PATCH' => '/api/audit-logs/'.$this->foreign['audit']->id,
            'PUT' => '/api/audit-logs/'.$this->foreign['audit']->id, 'DELETE' => '/api/audit-logs/'.$this->foreign['audit']->id] as $method => $path) {
            $this->json($method, $path, ['action' => 'forged'])->assertStatus(405)->assertJsonPath('data', null);
        }
    }

    public function test_foreign_role_assignment_cannot_enroll_users_or_change_grants(): void
    {
        $before = $this->snapshot();
        $path = '/api/users/'.$this->local['user']->id.'/roles';
        $foreign = $this->putJson($path, ['role_ids' => [$this->foreign['role']->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
        $missing = $this->putJson($path, ['role_ids' => [999999]])->assertUnprocessable();
        $this->assertSame($this->body($missing), $this->body($foreign));
        $this->putJson('/api/users/'.$this->foreign['user']->id.'/roles', ['role_ids' => [$this->local['role']->id]])->assertNotFound();
        $this->putJson('/api/roles/'.$this->foreign['role']->id.'/permissions', ['permission_ids' => [Permission::firstOrFail()->id]])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_admin_and_wildcard_permissions_never_authorize_foreign_model_instances(): void
    {
        foreach (['patient', 'appointment', 'treatment', 'note', 'task', 'audit'] as $resource) {
            foreach (['view', 'update', 'delete', 'archive', 'updateMedical'] as $ability) {
                $this->assertFalse(Gate::forUser($this->local['user'])->allows($ability, $this->foreign[$resource]), $resource.' '.$ability);
            }
        }
    }

    #[DataProvider('serviceAttacks')]
    public function test_services_requery_foreign_instances_inside_the_selected_clinic(string $operation): void
    {
        $before = $this->snapshot();
        try {
            match ($operation) {
                'patient.update' => app(PatientWorkflow::class)->update($this->foreign['patient'], ['notes' => 'Changed'], $this->local['user']),
                'patient.archive' => app(PatientWorkflow::class)->archive($this->foreign['patient'], $this->local['user']),
                'appointment.update' => app(AppointmentWorkflow::class)->update($this->foreign['appointment'], ['status' => 'checked_in'], $this->local['user']),
                'note.update' => app(ClinicalNoteWorkflow::class)->update($this->foreign['note'], ['note_text' => 'Changed'], $this->local['user']),
                'note.delete' => app(ClinicalNoteWorkflow::class)->delete($this->foreign['note'], $this->local['user']),
                'task.status' => app(TaskWorkflow::class)->update($this->foreign['task'], ['status' => 'completed']),
                'role.update' => app(RoleWorkflow::class)->update($this->foreign['role'], ['name' => 'Changed'], $this->local['user']),
                'role.delete' => app(RoleWorkflow::class)->delete($this->foreign['role'], $this->local['user']),
                'role.permissions' => app(RoleWorkflow::class)->syncPermissions($this->foreign['role'], [], $this->local['user']),
                'user.roles' => app(RoleWorkflow::class)->syncUserRoles($this->foreign['user'], [$this->local['role']->id], $this->local['user']),
                'appointment.create' => app(AppointmentWorkflow::class)->create($this->payload('appointments', $this->foreign), $this->local['user']),
                'note.create' => app(ClinicalNoteWorkflow::class)->create($this->payload('clinical-notes', $this->foreign), $this->local['user']),
                'task.create' => app(TaskWorkflow::class)->create($this->payload('tasks', $this->foreign)),
            };
            $this->fail('A service accepted a resource from another clinic.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function serviceAttacks(): array
    {
        return array_map(fn ($operation) => [$operation], [
            'patient.update', 'patient.archive', 'appointment.update', 'note.update', 'note.delete', 'task.status',
            'role.update', 'role.delete', 'role.permissions', 'user.roles', 'appointment.create', 'note.create', 'task.create',
        ]);
    }

    public function test_every_clinic_api_route_requires_authentication_and_tenant_resolution(): void
    {
        $accountRoutes = [
            'api/health' => ['GET', 'HEAD'], 'api/auth/token' => ['POST', 'DELETE'], 'api/tenants' => ['GET', 'HEAD'],
        ];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            if (isset($accountRoutes[$route->uri()])) {
                $this->assertEmpty(array_diff($route->methods(), $accountRoutes[$route->uri()]));

                continue;
            }
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), $route->uri());
            $this->assertContains('tenant', $route->gatherMiddleware(), $route->uri());
        }
    }

    private function clinic(Tenant $tenant, string $marker): array
    {
        return app(TenantContext::class)->run($tenant, function () use ($tenant, $marker): array {
            $user = User::factory()->create(['name' => $marker.' member']);
            $permission = Permission::firstOrCreate(['slug' => '*'], ['name' => 'All local permissions']);
            $role = Role::create(['name' => 'Admin', 'slug' => Role::ADMIN]);
            $role->permissions()->attach($permission);
            $user->roles()->attach($role);
            $patient = Patient::factory()->create(['firstName' => 'Shared', 'lastName' => 'Patient', 'notes' => $marker.' patient']);
            $dentist = Dentist::factory()->create(['first_name' => 'Shared', 'last_name' => 'Provider', 'email' => 'shared-provider@example.test']);
            $treatment = Treatment::factory()->create(['name' => 'Shared Treatment', 'active' => true, 'description' => $marker.' treatment']);
            $room = Room::factory()->create(['name' => 'Shared Room']);
            $chair = Chair::factory()->for($room)->create(['name' => 'Shared Chair']);
            $appointment = Appointment::factory()->create([
                'patient_id' => $patient->id, 'dentist_id' => $dentist->id, 'treatment_id' => $treatment->id,
                'room_id' => $room->id, 'chair_id' => $chair->id,
                'appointment_date_time' => today()->addHours(10), 'duration_minutes' => 30, 'status' => 'scheduled', 'description' => $marker.' appointment',
            ]);
            $note = ClinicalNote::factory()->create([
                'patient_id' => $patient->id, 'dentist_id' => $dentist->id, 'appointment_id' => $appointment->id,
                'author_user_id' => $user->id, 'updated_by_user_id' => $user->id, 'note_text' => $marker.' note',
            ]);
            $task = Task::factory()->create([
                'patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'assignee_id' => $user->id,
                'due_date' => today()->toDateString(), 'status' => 'pending', 'description' => $marker.' task',
            ]);
            $invoice = Invoice::factory()->create([
                'patient_id' => $patient->id, 'appointment_id' => $appointment->id,
                'invoice_number' => 'INV-SHARED', 'amount_due' => 100, 'amount_paid' => 25, 'status' => 'partial',
            ]);
            $request = Request::create('/internal');
            $request->attributes->set('request_id', $marker === 'ForeignSecret' ? 'foreign-correlation' : 'local-correlation');
            $audit = app(AuditLogService::class)->record('updated', 'patients', 'overlap', $user->id, $request);

            return compact('tenant', 'user', 'role', 'patient', 'dentist', 'treatment', 'room', 'chair', 'appointment', 'note', 'task', 'invoice', 'audit');
        });
    }

    private function payload(string $resource, array $clinic): array
    {
        return match ($resource) {
            'appointments' => [
                'patient_id' => $clinic['patient']->id, 'dentist_id' => $clinic['dentist']->id, 'treatment_id' => $clinic['treatment']->id,
                'room_id' => $clinic['room']->id, 'chair_id' => $clinic['chair']->id,
                'appointment_date_time' => today()->addHours(14)->toISOString(), 'appointment_type' => 'Review', 'description' => 'Review',
            ],
            'clinical-notes' => ['patient_id' => $clinic['patient']->id, 'dentist_id' => $clinic['dentist']->id, 'appointment_id' => $clinic['appointment']->id, 'note_text' => 'Review'],
            'tasks' => ['patient_id' => $clinic['patient']->id, 'appointment_id' => $clinic['appointment']->id, 'assignee_id' => $clinic['user']->id, 'due_date' => today()->toDateString(), 'description' => 'Review'],
            'invoices' => ['patient_id' => $clinic['patient']->id, 'appointment_id' => $clinic['appointment']->id, 'invoice_number' => 'INV-NEW', 'amount_due' => 100, 'status' => 'unpaid'],
        };
    }

    private function path(string $template, array $clinic): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($match) => (string) $clinic[$match[1]]->id, $template);
    }

    private function body($response): array
    {
        $body = $response->json();
        unset($body['requestId']);

        return $body;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['tenants', 'users', 'tenant_user', 'roles', 'role_user', 'permissions', 'permission_role',
            'patient', 'dentist', 'treatments', 'rooms', 'chairs', 'appointment', 'clinical_notes', 'tasks', 'invoices', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function foreignSnapshot(): array
    {
        $snapshot = [];
        foreach (['patient', 'dentist', 'treatments', 'rooms', 'chairs', 'appointment', 'clinical_notes', 'tasks', 'invoices', 'audit_logs', 'roles', 'role_user'] as $table) {
            $snapshot[$table] = DB::table($table)->where('tenant_id', $this->foreign['tenant']->id)->orderBy('id')
                ->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
