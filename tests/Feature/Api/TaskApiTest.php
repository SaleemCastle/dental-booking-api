<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_tasks_require_authentication(string $method, string $path, string $permission): void
    {
        $task = Task::factory()->create();
        $this->json($method, str_replace('{id}', $task->id, $path))
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('endpoints')]
    public function test_tasks_deny_users_without_permission(string $method, string $path, string $permission): void
    {
        Sanctum::actingAs(User::factory()->create());
        $task = Task::factory()->create();
        $original = $task->refresh()->getAttributes();
        $this->json($method, str_replace('{id}', $task->id, $path))
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame($original, $task->refresh()->getAttributes());
        $this->assertDatabaseCount('tasks', 1);
    }

    #[DataProvider('endpoints')]
    public function test_task_endpoints_accept_their_resource_permission(string $method, string $path, string $permission): void
    {
        $this->actingAsTaskUser($permission);
        $task = Task::factory()->create();
        $payload = str_ends_with($path, '/status') ? ['status' => 'completed'] : $this->payload();
        $this->json($method, str_replace('{id}', $task->id, $path), $payload)
            ->assertStatus($method === 'POST' ? 201 : 200);
    }

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/tasks', 'tasks.view'],
            ['GET', '/api/tasks/{id}', 'tasks.view'],
            ['POST', '/api/tasks', 'tasks.create'],
            ['PATCH', '/api/tasks/{id}', 'tasks.update'],
            ['PUT', '/api/tasks/{id}', 'tasks.update'],
            ['PATCH', '/api/tasks/{id}/status', 'tasks.update'],
            ['DELETE', '/api/tasks/{id}', 'tasks.delete'],
        ];
    }

    public function test_admin_can_perform_crud_and_receives_display_ready_fields(): void
    {
        $actor = $this->actingAsTaskUser(admin: true);
        $appointment = Appointment::factory()->create();
        $this->travelTo(now()->startOfSecond());
        $response = $this->postJson('/api/tasks', $this->payload([
            'patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id, 'assignee_id' => $actor->id,
        ]))
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('statusCode', 201)
            ->assertJsonPath('data.task.patient_id', $appointment->patient_id)
            ->assertJsonPath('data.task.appointment_id', $appointment->id)
            ->assertJsonPath('data.task.assignee_id', $actor->id)
            ->assertJsonPath('data.task.assigneeName', $actor->name)
            ->assertJsonPath('data.task.patientName', trim($appointment->patient->firstName.' '.$appointment->patient->lastName))
            ->assertJsonPath('data.task.appointmentStartsAt', $appointment->appointment_date_time->toISOString())
            ->assertJsonPath('data.task.priority', 'normal')->assertJsonPath('data.task.status', 'pending')
            ->assertJsonPath('data.task.completed_at', null)
            ->assertJsonStructure(['requestId', 'data' => ['task' => ['id', 'due_date', 'description', 'is_overdue', 'created_at', 'updated_at']]]);
        $identifier = $response->json('data.task.id');
        $this->getJson("/api/tasks/{$identifier}")->assertOk()->assertJsonPath('data.task.id', $identifier);
        $this->patchJson("/api/tasks/{$identifier}", ['description' => 'Call patient tomorrow', 'priority' => 'urgent'])
            ->assertOk()->assertJsonPath('data.task.description', 'Call patient tomorrow')
            ->assertJsonPath('data.task.priority', 'urgent')->assertJsonPath('data.task.appointment_id', $appointment->id);
        $this->putJson("/api/tasks/{$identifier}", ['due_date' => '2026-11-01'])
            ->assertOk()->assertJsonPath('data.task.due_date', '2026-11-01');
        $this->patchJson("/api/tasks/{$identifier}/status", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.task.status', 'completed');
        $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1, 'data.tasks');
        $this->deleteJson("/api/tasks/{$identifier}")->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $identifier]);
        $this->getJson("/api/tasks/{$identifier}")->assertNotFound();
    }

    public function test_view_permission_and_assignment_do_not_grant_write_access(): void
    {
        $actor = $this->actingAsTaskUser('tasks.view');
        $task = Task::factory()->create(['assignee_id' => $actor->id]);
        $this->postJson('/api/tasks', $this->payload())->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['description' => 'Unauthorized'])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
        $this->assertSame('pending', $task->refresh()->status);

        $unprivileged = User::factory()->create();
        $task->update(['assignee_id' => $unprivileged->id]);
        Sanctum::actingAs($unprivileged);
        $this->getJson('/api/tasks?assigned_to_me=1')->assertForbidden();
        $this->getJson("/api/tasks/{$task->id}")->assertForbidden();
    }

    public function test_resource_wildcard_permission_supports_task_management(): void
    {
        $this->actingAsTaskUser('tasks.*');
        $task = Task::factory()->create();
        $this->getJson('/api/tasks')->assertOk();
        $this->postJson('/api/tasks', $this->payload())->assertCreated();
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'completed'])->assertOk();
        $this->deleteJson("/api/tasks/{$task->id}")->assertOk();
    }

    public function test_general_tasks_allow_no_patient_or_appointment_and_default_priority_and_status(): void
    {
        $this->actingAsTaskUser('tasks.create');
        $this->postJson('/api/tasks', $this->payload())
            ->assertCreated()->assertJsonPath('data.task.patient_id', null)
            ->assertJsonPath('data.task.appointment_id', null)->assertJsonPath('data.task.patientName', null)
            ->assertJsonPath('data.task.appointmentStartsAt', null)
            ->assertJsonPath('data.task.priority', 'normal')->assertJsonPath('data.task.status', 'pending');
    }

    public function test_patient_only_and_appointment_links_are_supported_with_consistent_patient_ids(): void
    {
        $this->actingAsTaskUser('tasks.*');
        $appointment = Appointment::factory()->create();
        $this->postJson('/api/tasks', $this->payload(['patient_id' => $appointment->patient_id]))
            ->assertCreated()->assertJsonPath('data.task.patient_id', $appointment->patient_id)
            ->assertJsonPath('data.task.appointment_id', null);
        $response = $this->postJson('/api/tasks', $this->payload(['appointment_id' => $appointment->id]))
            ->assertCreated()->assertJsonPath('data.task.patient_id', $appointment->patient_id);
        $identifier = $response->json('data.task.id');
        $other = Appointment::factory()->create();
        $this->patchJson("/api/tasks/{$identifier}", ['appointment_id' => $other->id])
            ->assertOk()->assertJsonPath('data.task.patient_id', $other->patient_id);
        $this->patchJson("/api/tasks/{$identifier}", ['description' => 'Preserve linked appointment'])
            ->assertOk()->assertJsonPath('data.task.appointment_id', $other->id);
        $this->patchJson("/api/tasks/{$identifier}", ['appointment_id' => null])
            ->assertOk()->assertJsonPath('data.task.patient_id', $other->patient_id)
            ->assertJsonPath('data.task.appointment_id', null);
        $this->patchJson("/api/tasks/{$identifier}", ['patient_id' => null])
            ->assertOk()->assertJsonPath('data.task.patient_id', null);
    }

    public function test_relationship_mismatches_fail_without_modifying_the_task(): void
    {
        $this->actingAsTaskUser('tasks.*');
        $appointment = Appointment::factory()->create();
        $other = Patient::factory()->create();
        foreach ([$other->id, null] as $patientId) {
            $this->postJson('/api/tasks', $this->payload(['appointment_id' => $appointment->id, 'patient_id' => $patientId]))
                ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        }
        $task = Task::factory()->create(['patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id]);
        $original = $task->refresh()->getAttributes();
        $this->patchJson("/api/tasks/{$task->id}", ['patient_id' => $other->id, 'description' => 'Invalid change'])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->assertSame($original, $task->refresh()->getAttributes());
    }

    #[DataProvider('invalidFields')]
    public function test_task_fields_are_validated_on_create_and_update(string $field, mixed $value): void
    {
        $this->actingAsTaskUser('tasks.*');
        $task = Task::factory()->create();
        $original = $task->refresh()->getAttributes();
        $this->postJson('/api/tasks', $this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/tasks/{$task->id}", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $task->refresh()->getAttributes());
        $this->assertDatabaseCount('tasks', 1);
    }

    public static function invalidFields(): array
    {
        return [
            ['patient_id', 999999], ['patient_id', 'invalid'], ['appointment_id', 999999],
            ['assignee_id', 999999], ['assignee_id', null], ['assignee_id', ['invalid']],
            ['due_date', null], ['due_date', 'tomorrow'], ['due_date', '2026-02-30'],
            ['due_date', '2026-10-17T12:00:00Z'], ['priority', 'invalid'], ['priority', null],
            ['status', 'invalid'], ['status', null], ['description', ' '], ['description', null],
            ['description', ['invalid']], ['description', str_repeat('a', 16001)],
            ['completed_at', null], ['created_at', '2026-10-17'], ['updated_at', '2026-10-17'],
        ];
    }

    public function test_creation_requires_assignee_due_date_and_description(): void
    {
        $this->actingAsTaskUser('tasks.create');
        $this->postJson('/api/tasks', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee_id', 'due_date', 'description']);
    }

    public function test_assigned_to_me_filters_the_current_user_and_zero_disables_the_filter(): void
    {
        $actor = $this->actingAsTaskUser('tasks.view');
        $mine = Task::factory()->create(['assignee_id' => $actor->id]);
        Task::factory()->create();
        $this->getJson('/api/tasks?assigned_to_me=1')->assertOk()->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.id', $mine->id);
        $this->getJson('/api/tasks?assigned_to_me=0')->assertOk()->assertJsonCount(2, 'data.tasks');
    }

    public function test_overdue_and_due_today_include_only_open_tasks_with_calendar_date_boundaries(): void
    {
        $this->actingAsTaskUser('tasks.view');
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));

        foreach (Task::STATUSES as $status) {
            foreach (['2026-10-16', '2026-10-17', '2026-10-18'] as $date) {
                Task::factory()->create(['status' => $status, 'due_date' => $date]);
            }
        }

        $this->getJson('/api/tasks?overdue=1')->assertOk()->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.tasks.0.due_date', '2026-10-16')->assertJsonPath('data.tasks.0.is_overdue', true)
            ->assertJsonPath('data.tasks.1.is_overdue', true);
        $this->getJson('/api/tasks?due_today=1')->assertOk()->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.tasks.0.due_date', '2026-10-17')->assertJsonPath('data.tasks.0.is_overdue', false);
        $this->getJson('/api/tasks?overdue=0&due_today=0')->assertOk()->assertJsonCount(12, 'data.tasks');
        $this->getJson('/api/tasks?status=completed')->assertOk()->assertJsonCount(3, 'data.tasks');
        $this->getJson('/api/tasks?overdue=1&due_today=1')->assertOk()->assertJsonCount(0, 'data.tasks');
    }

    public function test_due_today_changes_at_midnight_and_yesterday_becomes_overdue(): void
    {
        $this->actingAsTaskUser('tasks.view');
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(23, 59, 59));
        $today = Task::factory()->create(['due_date' => '2026-10-17']);
        $tomorrow = Task::factory()->create(['due_date' => '2026-10-18']);
        $this->getJson('/api/tasks?due_today=1')->assertOk()->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.id', $today->id);
        $this->travel(1)->seconds();
        $this->getJson('/api/tasks?due_today=1')->assertOk()->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.id', $tomorrow->id);
        $this->getJson('/api/tasks?overdue=1')->assertOk()->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.id', $today->id);
    }

    public function test_filters_combine_by_assignment_patient_date_and_status_before_pagination(): void
    {
        $actor = $this->actingAsTaskUser('tasks.view');
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $patient = Patient::factory()->create();
        $matching = Task::factory(2)->create([
            'assignee_id' => $actor->id, 'patient_id' => $patient->id, 'due_date' => '2026-10-16', 'status' => 'pending',
        ]);
        Task::factory()->create(['patient_id' => $patient->id, 'due_date' => '2026-10-16']);
        Task::factory()->create(['assignee_id' => $actor->id, 'due_date' => '2026-10-16']);
        Task::factory()->create(['assignee_id' => $actor->id, 'patient_id' => $patient->id, 'due_date' => '2026-10-17']);
        Task::factory()->create(['assignee_id' => $actor->id, 'patient_id' => $patient->id, 'due_date' => '2026-10-16', 'status' => 'completed']);
        $this->getJson('/api/tasks?assigned_to_me=1&overdue=1&status=pending&patient_id='.$patient->id.'&per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.id', $matching[1]->id)
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.currentPage', 2)
            ->assertJsonPath('meta.pagination.lastPage', 2)->assertJsonPath('meta.pagination.hasMorePages', false);
    }

    public function test_filter_values_and_pagination_are_validated(): void
    {
        $this->actingAsTaskUser('tasks.view');
        $this->getJson('/api/tasks?assigned_to_me=true&overdue=yes&due_today=2&patient_id=999999&status=invalid&per_page=101&page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['assigned_to_me', 'overdue', 'due_today', 'patient_id', 'status', 'per_page', 'page']);
    }

    #[DataProvider('statusPairs')]
    public function test_status_endpoint_accepts_valid_status_changes_and_updates_completion_time(string $from, string $to): void
    {
        $this->actingAsTaskUser('tasks.update');
        $task = Task::factory()->create([
            'status' => $from, 'completed_at' => $from === 'completed' ? now()->subDay() : null,
        ]);
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => $to])
            ->assertOk()->assertJsonPath('data.task.status', $to);
        $task->refresh();

        if ($to === 'completed') {
            $this->assertNotNull($task->completed_at);
        } else {
            $this->assertNull($task->completed_at);
        }
    }

    public static function statusPairs(): array
    {
        $pairs = [];
        foreach (Task::STATUSES as $from) {
            foreach (Task::STATUSES as $to) {
                $pairs[$from.' to '.$to] = [$from, $to];
            }
        }

        return $pairs;
    }

    public function test_completion_retries_preserve_timestamps_and_generic_updates_can_reopen_tasks(): void
    {
        $this->actingAsTaskUser('tasks.*');
        $this->travelTo(now()->startOfSecond());
        $task = Task::factory()->create();
        $response = $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'completed'])->assertOk();
        $completedAt = $response->json('data.task.completed_at');
        $this->travel(5)->minutes();
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.task.completed_at', $completedAt);
        $this->putJson("/api/tasks/{$task->id}", ['description' => 'Keep completion time'])
            ->assertOk()->assertJsonPath('data.task.completed_at', $completedAt);
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.task.completed_at', null);
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])->assertOk();
        $this->assertNotSame($completedAt, $task->refresh()->completed_at->toISOString());
        $this->postJson('/api/tasks', $this->payload(['status' => 'completed']))->assertCreated();
    }

    public function test_status_endpoint_rejects_missing_invalid_and_non_status_fields(): void
    {
        $this->actingAsTaskUser('tasks.update');
        $task = Task::factory()->create();
        $this->patchJson("/api/tasks/{$task->id}/status", [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/tasks/{$task->id}/status", ['status' => 'completed', 'description' => 'Do not change', 'completed_at' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'completed_at']);
        $this->assertSame('pending', $task->refresh()->status);
    }

    public function test_missing_tasks_return_not_found_for_all_record_endpoints(): void
    {
        $this->actingAsTaskUser(admin: true);
        foreach (['GET', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $this->json($method, '/api/tasks/999999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        }
        $this->patchJson('/api/tasks/999999/status', ['status' => 'completed'])->assertNotFound();
    }

    public function test_tasks_preserve_patient_context_when_an_appointment_is_deleted_and_patients_are_archived(): void
    {
        $this->actingAsTaskUser(admin: true);
        $appointment = Appointment::factory()->create(['status' => 'scheduled']);
        $task = Task::factory()->create(['appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id]);
        $this->patchJson("/api/appointments/{$appointment->id}", ['patient_id' => Patient::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $appointment->patient->archive();
        $this->deleteJson("/api/appointments/{$appointment->id}")->assertOk();
        $this->getJson("/api/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.task.patient_id', $appointment->patient_id)
            ->assertJsonPath('data.task.appointment_id', null);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'assignee_id' => User::factory()->create()->id, 'due_date' => '2026-10-20', 'description' => 'Call patient after treatment.',
        ], $overrides);
    }

    private function actingAsTaskUser(string $permission = 'tasks.*', bool $admin = false): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => $admin ? 'Admin' : 'Task operator', 'slug' => $admin ? Role::ADMIN : 'task-operator']);

        if (! $admin) {
            [$resource, $action] = array_pad(explode('.', $permission, 2), 2, null);
            $grant = Permission::create(['name' => $permission, 'slug' => $permission, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($grant);
        }

        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
