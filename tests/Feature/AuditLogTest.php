<?php

namespace Tests\Feature;

use App\Events\ClinicalNoteChanged;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ClinicalNoteWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_records_identifiers_actor_and_immutable_utc_timestamp(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 17)->setTime(14, 30, 12, 123456));
        $actor = User::factory()->create();
        $entry = app(AuditLogService::class)->record('created', 'tasks', 42, $actor->id)->refresh();

        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertTrue($entry->actor->is($actor));
        $this->assertSame('created', $entry->action);
        $this->assertSame('tasks', $entry->resource_type);
        $this->assertSame('42', $entry->resource_id);
        $this->assertSame('2026-10-17T14:30:12.123456Z', $entry->occurred_at->toISOString());
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $entry->occurred_at);
        $this->assertNull($entry->ip_address);
        $this->assertNull($entry->user_agent);
        $this->assertNull($entry->request_metadata);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_system_operations_and_uuid_resources_are_supported(): void
    {
        $entry = app(AuditLogService::class)->record('archived', 'imports', 'b16fb750-8c4f-4a2c-a96a-7c26c07d434a', null)->refresh();

        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->actor);
        $this->assertSame('b16fb750-8c4f-4a2c-a96a-7c26c07d434a', $entry->resource_id);
    }

    public function test_request_context_is_allowlisted_bounded_and_does_not_include_sensitive_values(): void
    {
        $request = Request::create('/api/clinical-notes/17?secret=private-query', 'PATCH', [
            'note_text' => 'Private clinical findings', 'password' => 'private-password',
        ], ['session' => 'private-cookie'], [], [
            'REMOTE_ADDR' => '2001:db8::1',
            'HTTP_USER_AGENT' => "AuditClient\r\n".str_repeat('界', 600),
            'HTTP_AUTHORIZATION' => 'Bearer private-token',
            'HTTP_REFERER' => 'https://example.test/?secret=private-referrer',
            'HTTP_X_REQUEST_ID' => 'unvalidated-header',
        ]);
        $request->attributes->set('request_id', 'audit-request-123');
        $request->attributes->set('patient_name', 'Private patient name');
        $request->setRouteResolver(fn () => new Route(['PATCH'], 'api/clinical-notes/{clinical_note}', fn () => null));

        $entry = app(AuditLogService::class)->record('updated', 'clinical_notes', 17, null, $request)->refresh();

        $this->assertSame('2001:db8::1', $entry->ip_address);
        $this->assertSame('AuditClient'.str_repeat('界', 501), $entry->user_agent);
        $this->assertEquals([
            'request_id' => 'audit-request-123', 'method' => 'PATCH', 'route' => 'api/clinical-notes/{clinical_note}',
        ], $entry->request_metadata);
        $serialized = $entry->toJson();
        foreach (['Private', 'private-', 'secret=', 'unvalidated-header', 'note_text', 'password', 'authorization', 'patient_name'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
    }

    public function test_invalid_optional_context_is_omitted_and_missing_context_remains_nullable(): void
    {
        $request = Request::create('/internal', 'POST', [], [], [], ['REMOTE_ADDR' => 'invalid-ip']);
        $request->headers->remove('User-Agent');
        $request->attributes->set('request_id', 'invalid request id');
        $entry = app(AuditLogService::class)->record('created', 'tasks', 1, null, $request)->refresh();

        $this->assertNull($entry->ip_address);
        $this->assertNull($entry->user_agent);
        $this->assertSame(['method' => 'POST'], $entry->request_metadata);
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_event_identifiers_are_rejected(string $action, string $resourceType, string|int $resourceId, ?int $actorId): void
    {
        try {
            app(AuditLogService::class)->record($action, $resourceType, $resourceId, $actorId);
            $this->fail('Invalid audit identifiers must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('machine-readable', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidEntries(): array
    {
        return [
            'empty action' => ['', 'tasks', 1, null],
            'free-text action' => ['Changed private patient name', 'tasks', 1, null],
            'long action' => [str_repeat('a', 81), 'tasks', 1, null],
            'invalid resource type' => ['created', 'App\\Models\\Task', 1, null],
            'long resource type' => ['created', str_repeat('a', 81), 1, null],
            'empty resource ID' => ['created', 'tasks', '', null],
            'free-text resource ID' => ['created', 'tasks', 'private patient name', null],
            'long resource ID' => ['created', 'tasks', str_repeat('1', 129), null],
            'invalid actor ID' => ['created', 'tasks', 1, 0],
        ];
    }

    #[DataProvider('mutations')]
    public function test_existing_audit_rows_cannot_be_mutated_through_model_or_eloquent_operations(string $operation): void
    {
        $entry = app(AuditLogService::class)->record('created', 'tasks', 1, null)->refresh();
        $original = $entry->getAttributes();
        $query = AuditLog::query()->whereKey($entry->id);

        try {
            match ($operation) {
                'save' => $entry->forceFill(['action' => 'tampered'])->save(),
                'saveQuietly' => $entry->forceFill(['action' => 'tampered'])->saveQuietly(),
                'update' => $entry->update(),
                'updateQuietly' => $entry->updateQuietly(),
                'delete' => $entry->delete(),
                'deleteQuietly' => $entry->deleteQuietly(),
                'forceDelete' => $entry->forceDelete(),
                'destroy' => AuditLog::destroy($entry->id),
                'query.update' => $query->update(['action' => 'tampered']),
                'query.updateOrCreate' => $query->updateOrCreate(['id' => $entry->id]),
                'query.upsert' => $query->upsert([$original], ['id'], ['action']),
                'query.increment' => $query->increment('actor_id'),
                'query.decrement' => $query->decrement('actor_id'),
                'query.incrementEach' => $query->incrementEach(['actor_id' => 1]),
                'query.decrementEach' => $query->decrementEach(['actor_id' => 1]),
                'query.updateFrom' => $query->updateFrom(['action' => 'tampered']),
                'query.updateOrInsert' => $query->updateOrInsert(['id' => $entry->id], ['action' => 'tampered']),
                'query.delete' => $query->delete(),
                'query.forceDelete' => $query->forceDelete(),
                'query.truncate' => $query->truncate(),
            };
            $this->fail('Existing audit records must be immutable.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit records are append-only.', $exception->getMessage());
        }

        $this->assertSame($original, $entry->refresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function mutations(): array
    {
        return array_map(fn ($operation) => [$operation], [
            'save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete', 'destroy',
            'query.update', 'query.updateOrCreate', 'query.upsert', 'query.increment', 'query.decrement',
            'query.incrementEach', 'query.decrementEach', 'query.updateFrom', 'query.updateOrInsert', 'query.delete', 'query.forceDelete', 'query.truncate',
        ]);
    }

    public function test_deleting_an_actor_or_resource_retains_the_original_audit_identifiers(): void
    {
        $actor = User::factory()->create();
        $entry = app(AuditLogService::class)->record('created', 'users', $actor->id, $actor->id)->refresh();
        $original = $entry->getAttributes();

        $actor->delete();

        $this->assertSame($original, $entry->refresh()->getAttributes());
        $this->assertNull($entry->actor);
    }

    public function test_clinical_note_crud_records_only_safe_events_with_the_current_actor_and_request_context(): void
    {
        $author = $this->actingAsClinicalUser(Role::DENTIST);
        $appointment = Appointment::factory()->create();
        Event::fake([ClinicalNoteChanged::class]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeaders([
            'User-Agent' => 'ClinicClient/1.0', 'X-Request-Id' => 'clinical-audit-123',
        ]);
        $response = $this->postJson('/api/clinical-notes', $this->payload($appointment))->assertCreated();
        $noteId = $response->json('data.clinicalNote.id');
        $editor = $this->actingAsClinicalUser(Role::DENTIST);
        $this->patchJson("/api/clinical-notes/{$noteId}", ['note_text' => 'Private updated clinical findings'])->assertOk();
        $this->deleteJson("/api/clinical-notes/{$noteId}")->assertOk();

        $entries = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $entries->pluck('action')->all());
        $this->assertSame([$author->id, $editor->id, $editor->id], $entries->pluck('actor_id')->all());
        foreach ($entries as $entry) {
            $this->assertSame('clinical_notes', $entry->resource_type);
            $this->assertSame((string) $noteId, $entry->resource_id);
            $this->assertSame('192.0.2.10', $entry->ip_address);
            $this->assertSame('ClinicClient/1.0', $entry->user_agent);
            $this->assertSame('clinical-audit-123', $entry->request_metadata['request_id']);
            $this->assertStringNotContainsString('Private', $entry->toJson());
            $this->assertStringNotContainsString('note_text', $entry->toJson());
        }
        $this->assertSame(['POST', 'PATCH', 'DELETE'], $entries->map(fn ($entry) => $entry->request_metadata['method'])->all());
        $this->assertSame('api/clinical-notes', $entries[0]->request_metadata['route']);
        $this->assertSame('api/clinical-notes/{clinical_note}', $entries[1]->request_metadata['route']);
        Event::assertDispatchedTimes(ClinicalNoteChanged::class, 3);
    }

    public function test_denied_invalid_read_and_no_op_requests_do_not_create_mutation_audits(): void
    {
        $note = ClinicalNote::factory()->create();
        $this->actingAsClinicalUser(Role::RECEPTIONIST);
        $this->postJson('/api/clinical-notes', $this->payload($note->appointment))->assertForbidden();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['note_text' => 'Denied'])->assertForbidden();
        $this->deleteJson("/api/clinical-notes/{$note->id}")->assertForbidden();
        $this->actingAsClinicalUser(Role::DENTIST);
        $this->postJson('/api/clinical-notes', [])->assertUnprocessable();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['note_text' => null])->assertUnprocessable();
        $otherAppointment = Appointment::factory()->create();
        $this->postJson('/api/clinical-notes', array_replace($this->payload($note->appointment), ['patient_id' => $otherAppointment->patient_id]))
            ->assertUnprocessable();
        $this->patchJson("/api/clinical-notes/{$note->id}", ['patient_id' => $otherAppointment->patient_id])->assertUnprocessable();
        $this->patchJson("/api/clinical-notes/{$note->id}", [])->assertOk();
        $this->getJson("/api/clinical-notes/{$note->id}")->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_outer_transaction_rollback_removes_both_domain_change_and_audit(): void
    {
        $actor = User::factory()->create();
        $payload = $this->payload(Appointment::factory()->create());
        Event::fake([ClinicalNoteChanged::class]);
        DB::beginTransaction();

        try {
            app(ClinicalNoteWorkflow::class)->create($payload, $actor);
            $this->assertDatabaseCount('clinical_notes', 1);
            $this->assertDatabaseCount('audit_logs', 1);
            Event::assertNotDispatched(ClinicalNoteChanged::class);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('clinical_notes', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Event::assertNotDispatched(ClinicalNoteChanged::class);
    }

    #[DataProvider('clinicalMutations')]
    public function test_audit_write_failure_rolls_back_clinical_note_mutations(string $action): void
    {
        $note = ClinicalNote::factory()->create();
        $actor = User::factory()->create();
        $original = $note->refresh()->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->with($action, 'clinical_notes', \Mockery::type('int'), $actor->id)
            ->andThrow(new RuntimeException('Audit storage unavailable.'));
        Event::fake([ClinicalNoteChanged::class]);
        $workflow = app(ClinicalNoteWorkflow::class);

        try {
            match ($action) {
                'created' => $workflow->create($this->payload($note->appointment), $actor),
                'updated' => $workflow->update($note, ['note_text' => 'Uncommitted findings'], $actor),
                'deleted' => $workflow->delete($note, $actor),
            };
            $this->fail('The audit failure must propagate to the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertSame($original, $note->refresh()->getAttributes());
        $this->assertDatabaseCount('clinical_notes', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        Event::assertNotDispatched(ClinicalNoteChanged::class);
    }

    public static function clinicalMutations(): array
    {
        return [['created'], ['updated'], ['deleted']];
    }

    private function actingAsClinicalUser(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(Appointment $appointment): array
    {
        return [
            'patient_id' => $appointment->patient_id,
            'dentist_id' => $appointment->dentist_id,
            'appointment_id' => $appointment->id,
            'note_text' => 'Private clinical findings',
        ];
    }
}
