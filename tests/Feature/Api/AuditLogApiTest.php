<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_audit_read_endpoints_require_authentication(): void
    {
        $entry = $this->entry();
        foreach (['/api/audit-logs', '/api/audit-logs/filters', "/api/audit-logs/{$entry->id}"] as $path) {
            $this->getJson($path)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('readerRoles')]
    public function test_admins_and_auditors_can_read_logs_and_safe_display_fields(string $role): void
    {
        $reader = $this->actingAsRole($role);
        $request = Request::create('/internal', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.20', 'HTTP_USER_AGENT' => 'ClinicClient/1.0']);
        $request->attributes->set('request_id', 'logged-request-id');
        $entry = app(AuditLogService::class)->record('created', 'patients', 9, $reader->id, $request);
        $this->withHeader('X-Request-Id', 'read-request-id');
        $this->getJson('/api/audit-logs')->assertOk()
            ->assertJsonPath('data.auditLogs.0.id', $entry->id)
            ->assertJsonPath('data.auditLogs.0.actor_id', $reader->id)
            ->assertJsonPath('data.auditLogs.0.actorName', $reader->name)
            ->assertJsonPath('data.auditLogs.0.resource_id', '9')
            ->assertJsonPath('requestId', 'read-request-id')
            ->assertJsonPath('data.auditLogs.0.request_metadata.request_id', 'logged-request-id')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonMissingPath('data.auditLogs.0.actor')
            ->assertJsonMissingPath('data.auditLogs.0.email');
        $this->getJson("/api/audit-logs/{$entry->id}")->assertOk()
            ->assertJsonPath('data.auditLog.ip_address', '192.0.2.20')
            ->assertJsonPath('data.auditLog.user_agent', 'ClinicClient/1.0')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/audit-logs/filters')->assertOk()
            ->assertExactJson([
                'success' => true, 'statusCode' => 200, 'message' => 'Audit filter options retrieved.', 'code' => null,
                'data' => ['actions' => ['created'], 'resourceTypes' => ['patients']],
                'errors' => null, 'meta' => null, 'requestId' => 'read-request-id',
            ]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function readerRoles(): array
    {
        return [[Role::ADMIN], [Role::AUDITOR]];
    }

    #[DataProvider('deniedPermissions')]
    public function test_other_roles_cannot_use_permission_shortcuts_to_read_audits(string $permission): void
    {
        $this->actingAsRole(Role::RECEPTIONIST, $permission);
        $entry = $this->entry();
        foreach (['/api/audit-logs', '/api/audit-logs/filters', "/api/audit-logs/{$entry->id}"] as $path) {
            $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function deniedPermissions(): array
    {
        return [['*'], ['audit_logs.*'], ['audit_logs.view'], ['viewAny'], ['view']];
    }

    public function test_access_uses_fresh_canonical_roles_instead_of_names_or_cached_assignments(): void
    {
        $reader = $this->actingAsRole(Role::AUDITOR);
        $reader->load('roles');
        $reader->roles()->detach();
        $fakeRole = Role::create(['name' => 'admin', 'slug' => 'custom']);
        $reader->roles()->attach($fakeRole);
        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_lists_are_paginated_and_sorted_by_time_then_id_descending(): void
    {
        $this->actingAsRole(Role::AUDITOR);
        $oldest = $this->entry('created', 'patients', 1, at: '2026-10-18T10:00:00Z');
        $middle = $this->entry('updated', 'patients', 2, at: '2026-10-18T11:00:00Z');
        $newest = $this->entry('archived', 'patients', 3, at: '2026-10-18T11:00:00Z');
        $this->getJson('/api/audit-logs?per_page=2')->assertOk()
            ->assertJsonPath('data.auditLogs.0.id', $newest->id)->assertJsonPath('data.auditLogs.1.id', $middle->id)
            ->assertJsonPath('meta.pagination.currentPage', 1)->assertJsonPath('meta.pagination.lastPage', 2)
            ->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.pagination.hasMorePages', true);
        $this->getJson('/api/audit-logs?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data.auditLogs')->assertJsonPath('data.auditLogs.0.id', $oldest->id)
            ->assertJsonPath('meta.pagination.from', 3)->assertJsonPath('meta.pagination.to', 3)
            ->assertJsonPath('meta.pagination.hasMorePages', false);
    }

    public function test_filters_combine_and_utc_ranges_preserve_microsecond_boundaries(): void
    {
        $actor = $this->actingAsRole(Role::ADMIN);
        $otherActor = User::factory()->create();
        $request = Request::create('/internal');
        $request->attributes->set('request_id', 'correlation-123');
        $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00.123456Z'));
        $target = app(AuditLogService::class)->record('updated', 'patients', 42, $actor->id, $request);
        $this->entry('updated', 'patients', 42, $actor->id, '2026-10-18T12:00:00.123455Z');
        $this->entry('updated', 'patients', 42, $otherActor->id, '2026-10-18T12:00:00.123456Z');
        $this->entry('created', 'users', 42, $actor->id, '2026-10-18T12:00:00.123457Z');
        $query = http_build_query([
            'actor_id' => $actor->id, 'action' => 'updated', 'resource_type' => 'patients', 'resource_id' => '42',
            'request_id' => 'correlation-123', 'from' => '2026-10-18T07:00:00.123456-05:00', 'to' => '2026-10-18T12:00:00.123456Z',
        ]);
        $this->getJson('/api/audit-logs?'.$query)->assertOk()->assertJsonCount(1, 'data.auditLogs')
            ->assertJsonPath('data.auditLogs.0.id', $target->id)
            ->assertJsonPath('data.auditLogs.0.occurred_at', '2026-10-18T12:00:00.123456Z');
        $range = http_build_query(['from' => '2026-10-18T12:00:00.123456Z', 'to' => '2026-10-18T12:00:00.123456Z']);
        $this->getJson('/api/audit-logs?'.$range)->assertOk()->assertJsonCount(2, 'data.auditLogs');
        $this->getJson('/api/audit-logs?to=2026-10-18T12:00:00.123455Z')->assertOk()->assertJsonCount(1, 'data.auditLogs');
        $this->getJson('/api/audit-logs?from=2026-10-18T12:00:00.123457Z')->assertOk()->assertJsonCount(1, 'data.auditLogs');
        $this->getJson('/api/audit-logs?resource_type=users')->assertOk()->assertJsonCount(1, 'data.auditLogs');
        $this->getJson('/api/audit-logs?request_id=missing')->assertOk()->assertJsonCount(0, 'data.auditLogs');
    }

    public function test_deleted_actors_and_resources_remain_queryable_and_null_actors_are_distinct(): void
    {
        $this->actingAsRole(Role::AUDITOR);
        $actor = User::factory()->create();
        $deletedActorId = $actor->id;
        $entry = $this->entry('deleted', 'users', $deletedActorId, $deletedActorId);
        $this->entry('created', 'users', 999, null);
        $actor->delete();
        $this->getJson('/api/audit-logs?actor_id='.$deletedActorId)->assertOk()->assertJsonCount(1, 'data.auditLogs')
            ->assertJsonPath('data.auditLogs.0.actor_id', $deletedActorId)->assertJsonPath('data.auditLogs.0.actorName', null);
        $this->getJson("/api/audit-logs/{$entry->id}")->assertOk()->assertJsonPath('data.auditLog.resource_id', (string) $deletedActorId);
        $this->getJson('/api/audit-logs?actor_is_null=1')->assertOk()->assertJsonCount(1, 'data.auditLogs')
            ->assertJsonPath('data.auditLogs.0.actor_id', null);
        $this->getJson('/api/audit-logs?actor_is_null=0')->assertOk()->assertJsonCount(1, 'data.auditLogs');
    }

    public function test_unknown_filter_values_and_empty_history_return_empty_lists(): void
    {
        $this->actingAsRole(Role::AUDITOR);
        $this->getJson('/api/audit-logs')->assertOk()->assertJsonPath('data.auditLogs', [])
            ->assertJsonPath('meta.pagination.perPage', 15)->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.pagination.from', null)->assertJsonPath('meta.pagination.to', null);
        $this->getJson('/api/audit-logs/filters')->assertOk()->assertJsonPath('data.actions', [])->assertJsonPath('data.resourceTypes', []);
        $this->getJson('/api/audit-logs?action=future_action&resource_type=unknown&resource_id=missing&actor_id=99999')->assertOk()
            ->assertJsonPath('data.auditLogs', []);
        $this->getJson('/api/audit-logs/99999')->assertNotFound();
        $this->getJson('/api/audit-logs/not-an-id')->assertNotFound();
    }

    public function test_filter_options_are_sorted_unique_and_not_hardcoded(): void
    {
        $this->actingAsRole(Role::ADMIN);
        $this->entry('updated', 'users');
        $this->entry('created', 'patients');
        $this->entry('created', 'patients');
        $this->entry('future_action', 'future_resources');
        $this->getJson('/api/audit-logs/filters')->assertOk()
            ->assertJsonPath('data.actions', ['created', 'future_action', 'updated'])
            ->assertJsonPath('data.resourceTypes', ['future_resources', 'patients', 'users']);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_standard_validation_errors(array $filters, string $field): void
    {
        $this->actingAsRole(Role::ADMIN);
        $this->getJson('/api/audit-logs?'.http_build_query($filters))->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidFilters(): array
    {
        return [
            [['per_page' => 101], 'per_page'], [['per_page' => 0], 'per_page'], [['page' => 0], 'page'],
            [['actor_id' => -1], 'actor_id'], [['actor_id' => 'bad'], 'actor_id'], [['actor_is_null' => 'true'], 'actor_is_null'],
            [['actor_id' => 1, 'actor_is_null' => 1], 'actor_id'], [['action' => 'Private free text'], 'action'],
            [['resource_type' => 'Invalid Type'], 'resource_type'], [['resource_id' => 'invalid value'], 'resource_id'],
            [['request_id' => 'invalid value'], 'request_id'], [['from' => 'yesterday'], 'from'],
            [['from' => '2026-10-18'], 'from'], [['from' => '2026-10-18T12:00:00'], 'from'],
            [['to' => '2026-02-30T12:00:00Z'], 'to'], [['from' => '2026-10-18T25:00:00Z'], 'from'],
            [['from' => '2026-10-18T12:00:00.2Z', 'to' => '2026-10-18T12:00:00.1Z'], 'to'],
        ];
    }

    public function test_reads_expose_only_allowlisted_metadata_and_never_serialize_the_user_model(): void
    {
        $reader = $this->actingAsRole(Role::ADMIN);
        $entry = new AuditLog;
        $entry->forceFill([
            'actor_id' => $reader->id, 'action' => 'updated', 'resource_type' => 'patients', 'resource_id' => '1',
            'occurred_at' => now('UTC'), 'request_metadata' => [
                'method' => 'PATCH', 'password' => 'Private-secret', 'body' => 'Private clinical text',
                'route' => ['clinical_text' => 'Private nested clinical text'],
            ],
        ])->save();
        $reader->update(['name' => 'Current actor name']);
        $response = $this->getJson("/api/audit-logs/{$entry->id}")->assertOk()
            ->assertJsonPath('data.auditLog.actorName', 'Current actor name')
            ->assertJsonPath('data.auditLog.request_metadata', ['method' => 'PATCH']);
        $this->assertStringNotContainsString('Private', $response->getContent());
        $this->assertStringNotContainsString($reader->email, $response->getContent());
        $this->assertStringNotContainsString('permissions', $response->getContent());
    }

    public function test_empty_redacted_request_metadata_is_an_object_instead_of_an_array(): void
    {
        $this->actingAsRole(Role::AUDITOR);
        $entry = new AuditLog;
        $entry->forceFill([
            'action' => 'created', 'resource_type' => 'patients', 'resource_id' => '1',
            'occurred_at' => now('UTC'), 'request_metadata' => ['body' => 'Private clinical text'],
        ])->save();
        $response = $this->getJson("/api/audit-logs/{$entry->id}")->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->data->auditLog->request_metadata);
        $this->assertStringNotContainsString('Private', $response->getContent());
    }

    public function test_there_are_no_write_endpoints_even_for_admins_with_wildcard_permissions(): void
    {
        $reader = $this->actingAsRole(Role::ADMIN, '*');
        $entry = $this->entry(actorId: $reader->id);
        $original = $entry->getAttributes();
        $this->postJson('/api/audit-logs', ['action' => 'forged'])->assertStatus(405);
        foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
            $this->json($method, "/api/audit-logs/{$entry->id}", ['action' => 'forged'])->assertStatus(405);
        }
        $this->assertFalse(Gate::forUser($reader)->allows('update', $entry));
        $this->assertSame($original, $entry->refresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    private function actingAsRole(string $slug, ?string $permission = null): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        if ($permission !== null) {
            $role->permissions()->attach(Permission::create(['name' => 'Test permission', 'slug' => $permission]));
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function entry(string $action = 'created', string $resourceType = 'patients', string|int $resourceId = 1, ?int $actorId = null, ?string $at = null): AuditLog
    {
        if ($at !== null) {
            $this->travelTo(CarbonImmutable::parse($at));
        }

        return app(AuditLogService::class)->record($action, $resourceType, $resourceId, $actorId)->refresh();
    }
}
