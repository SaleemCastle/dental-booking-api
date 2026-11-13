<?php

namespace Tests\Feature\Api;

use App\Models\ConversationMessage;
use App\Models\ConversationThread;
use App\Models\ConversationThreadState;
use App\Models\MessagingUserPermission;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MessagingService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CommunicationConsentFixtures;
use Tests\TestCase;

class CommunicationGovernanceApiTest extends TestCase
{
    use CommunicationConsentFixtures, RefreshDatabase;

    private User $owner;

    private User $peer;

    private User $admin;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->member('owner', MessagingService::PERMISSION_FIELDS);
        $this->peer = $this->member('peer', ['patient_view', 'patient_send', 'internal_view', 'internal_send']);
        $this->admin = $this->member('admin', MessagingService::PERMISSION_FIELDS);
        $this->patient = Patient::factory()->create();
        $this->grantCommunicationConsent($this->patient, $this->owner, 'in_app');
        Sanctum::actingAs($this->owner);
    }

    private function member(string $slug, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['slug' => $slug, 'name' => $slug]);
        $user->roles()->attach($role);
        foreach ($permissions as $permission) {
            $slug = 'messaging.'.str_replace('_', '.', $permission);
            $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        return $user;
    }

    private function thread(bool $patient = true, array $userIds = []): int
    {
        return $this->postJson('/api/conversation-threads', ['subject' => 'Confidential conversation', 'user_ids' => $userIds,
            ...($patient ? ['patient_id' => $this->patient->id, 'use_case' => 'care'] : [])])->assertCreated()->json('data.thread.id');
    }

    private function message(int $thread, string $key = 'send-1'): int
    {
        $data = ['body' => 'Sensitive message body', 'idempotency_key' => $key];
        $token = $this->postJson("/api/conversation-threads/$thread/confirmations", $data)->assertCreated()->json('data.confirmation_token');

        return $this->postJson("/api/conversation-threads/$thread/messages", [...$data, 'confirmation_token' => $token,
            'send_confirmed' => true, 'consent_acknowledged' => true])->assertCreated()->json('data.message.id');
    }

    private function policy(bool $escalation = true): void
    {
        $this->putJson('/api/communication-governance/policy', ['retention_days' => 365, 'archive_after_days' => 1,
            'escalation_enabled' => $escalation, 'policy_reference' => 'Clinic reviewed policy REF-23'])->assertCreated();
    }

    public function test_view_and_send_are_independent_and_neither_grants_tenant_wide_discovery(): void
    {
        $thread = $this->thread(userIds: [$this->peer->id]);
        MessagingUserPermission::create(['user_id' => $this->peer->id, 'patient_send' => false]);
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$thread/messages")->assertOk();
        $this->postJson("/api/conversation-threads/$thread/messages", ['body' => 'Denied', 'idempotency_key' => 'denied'])->assertForbidden();
        MessagingUserPermission::where('user_id', $this->peer->id)->update(['patient_view' => false, 'patient_send' => true]);
        $this->getJson("/api/conversation-threads/$thread")->assertNotFound();
        $this->postJson("/api/conversation-threads/$thread/messages", ['body' => 'Denied', 'idempotency_key' => 'denied'])->assertNotFound();
        $this->getJson('/api/conversation-threads')->assertJsonCount(0, 'data.threads');
    }

    public function test_internal_sending_does_not_imply_internal_viewing(): void
    {
        $id = $this->thread(false, [$this->peer->id]);
        MessagingUserPermission::create(['user_id' => $this->peer->id, 'internal_view' => false, 'internal_receive' => false]);
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        $this->postJson("/api/conversation-threads/$id/messages", ['body' => 'Denied', 'idempotency_key' => 'denied'])->assertNotFound();
    }

    public function test_handoff_revokes_previous_assignee_and_keeps_authorship_without_broadening_scope(): void
    {
        $id = $this->thread();
        $message = $this->message($id);
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $this->peer->id, 'reason' => 'Care responsibility transferred'])->assertCreated();
        $this->getJson("/api/conversation-threads/$id/messages")->assertNotFound();
        $this->postJson("/api/conversation-threads/$id/export", ['reason' => 'Known ID attack'])->assertNotFound();
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id/messages")->assertOk()->assertJsonPath('data.messages.0.actor_id', $this->owner->id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $message, 'actor_id' => $this->owner->id]);
        $this->assertDatabaseHas('conversation_thread_states', ['thread_id' => $id, 'assigned_to' => $this->peer->id]);
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
    }

    public function test_handoff_requires_permission_and_cannot_assign_admins_or_read_only_staff(): void
    {
        $id = $this->thread(userIds: [$this->peer->id]);
        Sanctum::actingAs($this->peer);
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $this->owner->id, 'reason' => 'No grant'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $this->admin->id, 'reason' => 'Forbidden admin'])->assertForbidden();
        MessagingUserPermission::create(['user_id' => $this->peer->id, 'patient_send' => false]);
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $this->peer->id, 'reason' => 'Cannot assume sends'])->assertForbidden();
    }

    public function test_membership_changes_invalidate_prepared_send_confirmation(): void
    {
        $id = $this->thread(userIds: [$this->peer->id]);
        Sanctum::actingAs($this->peer);
        $payload = ['body' => 'Prepared for old recipients', 'idempotency_key' => 'before-handoff'];
        $token = $this->postJson("/api/conversation-threads/$id/confirmations", $payload)->assertCreated()->json('data.confirmation_token');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $this->peer->id, 'reason' => 'New responsibility'])->assertCreated();
        Sanctum::actingAs($this->peer);
        $this->postJson("/api/conversation-threads/$id/messages", [...$payload, 'confirmation_token' => $token,
            'send_confirmed' => true, 'consent_acknowledged' => true])->assertUnprocessable();
        $this->message($id, 'after-handoff');
        $recipients = ConversationMessage::firstOrFail()->recipient_ids;
        $oldParticipant = DB::table('conversation_participants')->where('thread_id', $id)->where('user_id', $this->owner->id)->value('id');
        $this->assertNotContains($oldParticipant, $recipients);
    }

    public function test_optional_escalation_grants_only_selected_supervisor_and_rescinds_temporary_access(): void
    {
        $id = $this->thread();
        $supervisor = $this->member('supervisor', ['patient_view', 'supervise']);
        $this->postJson("/api/conversation-threads/$id/escalate", ['user_id' => $supervisor->id, 'reason' => 'Unresolved question'])->assertForbidden();
        $this->policy();
        Sanctum::actingAs($supervisor);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/conversation-threads/$id/escalate", ['user_id' => $supervisor->id, 'reason' => 'Sensitive unresolved message'])->assertCreated();
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        Sanctum::actingAs($supervisor);
        $this->getJson("/api/conversation-threads/$id")->assertOk();
        $this->postJson("/api/conversation-threads/$id/messages", ['body' => 'No send grant', 'idempotency_key' => 'supervisor'])->assertForbidden();
        $this->postJson("/api/conversation-threads/$id/resolve", ['reason' => 'Review completed'])->assertCreated();
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        $this->assertDatabaseHas('conversation_thread_states', ['thread_id' => $id, 'status' => 'resolved']);
    }

    public function test_policy_archive_and_legal_hold_preserve_originals_and_block_sends(): void
    {
        $this->policy();
        $held = $this->thread();
        $archived = $this->thread();
        $message = $this->message($archived);
        $this->postJson("/api/conversation-threads/$held/hold", ['enabled' => true, 'reason' => 'Clinic preservation decision'])->assertCreated();
        $this->getJson("/api/conversation-threads/$held")->assertJsonPath('data.retention.indefinite', true)->assertJsonPath('data.retention.deletion_authorized', false);
        $this->getJson("/api/conversation-threads/$archived")->assertJsonPath('data.retention.minimum_days', 365)->assertJsonPath('data.retention.indefinite', false);
        $this->travel(2)->days();
        $this->artisan('communications:archive')->assertSuccessful();
        $this->assertDatabaseHas('conversation_thread_states', ['thread_id' => $held, 'status' => 'open', 'legal_hold' => true]);
        $this->assertDatabaseHas('conversation_thread_states', ['thread_id' => $archived, 'status' => 'archived']);
        $this->getJson('/api/conversation-threads')->assertJsonCount(1, 'data.threads');
        $this->getJson('/api/conversation-threads?archived=1')->assertJsonCount(2, 'data.threads');
        $this->getJson("/api/conversation-threads/$archived/messages")->assertOk();
        $this->postJson("/api/conversation-threads/$archived/confirmations", ['body' => 'Blocked', 'idempotency_key' => 'archived'])->assertConflict();
        $this->assertDatabaseHas('conversation_messages', ['id' => $message, 'body' => 'Sensitive message body']);
        $this->postJson("/api/conversation-threads/$archived/archive", ['archived' => false, 'reason' => 'Resume care'])->assertCreated();
        $this->assertDatabaseHas('communication_governance_policies', ['version' => 1, 'retention_days' => 365]);
    }

    public function test_controlled_redaction_retains_original_and_records_actor_reason_and_time(): void
    {
        $id = $this->thread(userIds: [$this->peer->id]);
        $message = $this->message($id);
        Sanctum::actingAs($this->peer);
        $this->postJson("/api/conversation-threads/$id/redact", ['message_id' => $message, 'reason' => 'No redact grant'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $response = $this->postJson("/api/conversation-threads/$id/redact", ['message_id' => $message, 'reason' => 'Entered in wrong thread'])->assertCreated()
            ->assertJsonPath('data.event.actor_id', $this->owner->id)->assertJsonPath('data.event.metadata.original_retained', true);
        $this->assertNotNull($response->json('data.event.occurred_at'));
        $this->getJson("/api/conversation-threads/$id/messages")->assertJsonPath('data.messages.0.body', null);
        $this->postJson("/api/conversation-threads/$id/export", ['reason' => 'Authorized review'])->assertOk()->assertJsonPath('data.messages.0.body', null);
        $this->assertDatabaseHas('conversation_messages', ['id' => $message, 'body' => 'Sensitive message body']);
        $this->assertDatabaseHas('conversation_governance_events', ['message_id' => $message, 'reason' => 'Entered in wrong thread']);
    }

    public function test_sensitive_reads_and_exports_are_logged_and_history_permission_is_separate(): void
    {
        $id = $this->thread(userIds: [$this->peer->id]);
        $this->message($id);
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id/messages")->assertOk();
        $this->getJson("/api/conversation-threads/$id/history")->assertForbidden();
        $this->postJson("/api/conversation-threads/$id/export", ['reason' => 'No export grant'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/conversation-threads/$id/export", ['reason' => 'Clinic review', 'per_page' => 1])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/conversation-threads/$id/history")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_history_accessed', 'actor_id' => $this->peer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'resource_type' => 'conversation_governance_events']);
        $this->assertStringNotContainsString('Sensitive message body', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_deactivation_and_membership_removal_preserve_identity_and_remove_future_access(): void
    {
        $id = $this->thread();
        $message = $this->message($id);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/users/'.$this->owner->id.'/communication-access/deactivate')->assertOk();
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
        $this->postJson("/api/conversation-threads/$id/messages", ['body' => 'Denied', 'idempotency_key' => 'inactive'])->assertNotFound();
        $this->deleteJson('/api/users/'.$this->peer->id)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->deleteJson('/api/users/'.$this->owner->id)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
        $this->assertDatabaseHas('conversation_messages', ['id' => $message, 'actor_id' => $this->owner->id]);
        $this->assertDatabaseMissing('tenant_user', ['user_id' => $this->owner->id]);
    }

    public function test_settings_cannot_be_used_to_self_grant_patient_access_or_admin_participation(): void
    {
        $id = $this->thread();
        $this->patchJson('/api/users/'.$this->admin->id, ['password' => 'attempted-account-takeover'])->assertForbidden();
        $this->putJson('/api/users/'.$this->owner->id.'/messaging-permissions', ['export' => true])->assertForbidden();
        Sanctum::actingAs($this->peer);
        $this->getJson('/api/communication-governance/policy')->assertForbidden();
        $this->putJson('/api/communication-governance/policy', ['retention_days' => 1, 'archive_after_days' => 1,
            'escalation_enabled' => true, 'policy_reference' => 'Unauthorized change'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/users/'.$this->admin->id.'/messaging-permissions', ['patient_view' => true, 'patient_send' => true])->assertForbidden();
        $this->getJson("/api/conversation-threads/$id/history")->assertNotFound();
        $this->postJson("/api/conversation-threads/$id/export", ['reason' => 'Admin ID attack'])->assertNotFound();
        MessagingUserPermission::create(['user_id' => $this->admin->id, 'settings_manage' => false]);
        $this->putJson('/api/users/'.$this->peer->id.'/messaging-permissions', ['patient_view' => true])->assertForbidden();
    }

    public function test_delivery_history_requires_both_source_access_and_history_permission(): void
    {
        $view = Permission::create(['slug' => 'patients.view', 'name' => 'View patients']);
        $this->peer->roles()->first()->permissions()->attach($view);
        $this->owner->roles()->first()->permissions()->attach($view);
        $url = '/api/patients/'.$this->patient->id.'/communications';
        Sanctum::actingAs($this->peer);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson($url)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery_history_accessed', 'resource_id' => (string) $this->patient->id]);
    }

    public function test_recorded_policy_versions_are_immutable(): void
    {
        $this->policy();
        $this->policy(false);
        $this->assertDatabaseCount('communication_governance_policies', 2);
        $this->assertDatabaseHas('communication_governance_policies', ['version' => 1, 'escalation_enabled' => true]);
        $this->expectException(QueryException::class);
        DB::table('communication_governance_policies')->update(['retention_days' => 1]);
    }

    public function test_foreign_thread_message_and_assignee_ids_cannot_cross_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $thread] = app(TenantContext::class)->run($tenant, function () {
            $user = $this->member('foreign', ['patient_view', 'patient_send']);
            $thread = ConversationThread::create(['subject' => 'Foreign secret', 'use_case' => 'internal', 'created_by' => $user->id]);
            $thread->participants()->create(['user_id' => $user->id]);

            return [$user, $thread];
        });
        foreach (['handoff', 'escalate', 'redact', 'export', 'archive', 'hold', 'resolve'] as $action) {
            $this->postJson("/api/conversation-threads/$thread->id/$action", ['reason' => 'ID attack', 'user_id' => $user->id, 'message_id' => 1, 'archived' => true, 'enabled' => true])->assertNotFound();
        }
        $this->getJson("/api/conversation-threads/$thread->id/history")->assertNotFound();
        $id = $this->thread();
        $this->postJson("/api/conversation-threads/$id/handoff", ['user_id' => $user->id, 'reason' => 'Cross tenant target'])->assertNotFound();
    }

    public function test_governance_history_cannot_be_silently_mutated(): void
    {
        $id = $this->thread();
        $this->postJson("/api/conversation-threads/$id/archive", ['archived' => true, 'reason' => 'Retain conversation'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('conversation_governance_events')->update(['reason' => 'Tampered']);
    }

    public function test_database_blocks_cross_tenant_state_links(): void
    {
        $id = $this->thread();
        $tenant = Tenant::factory()->create();
        $foreign = User::factory()->forTenant($tenant)->create();
        $this->expectException(QueryException::class);
        ConversationThreadState::where('thread_id', $id)->update(['assigned_to' => $foreign->id]);
    }

    public function test_unexpected_exceptions_do_not_log_message_content_or_sql_bindings(): void
    {
        $id = $this->thread();
        $message = $this->message($id);
        Log::spy();
        $secret = 'Sensitive SQL binding must never be logged';
        $audit = \Mockery::mock(\App\Services\AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException($secret));
        $this->app->instance(\App\Services\AuditLogService::class, $audit);
        $this->postJson("/api/conversation-threads/$id/redact", ['message_id' => $message, 'reason' => $secret])->assertStatus(500)->assertDontSee($secret);
        Log::shouldHaveReceived('error')->once()->with('Unhandled API exception.', \Mockery::on(fn ($context) => isset($context['exception_class']) && ! str_contains(json_encode($context), $secret) && ! isset($context['exception'])));
    }
}
