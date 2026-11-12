<?php

namespace Tests\Feature\Api;

use App\Models\CommunicationConsent;
use App\Models\ConversationThread;
use App\Models\MessagingUserPermission;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $peer;

    private User $admin;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->member('dentist');
        $this->peer = $this->member('hygienist');
        $this->admin = $this->member('admin');
        $this->patient = Patient::factory()->create();
        Sanctum::actingAs($this->staff);
        $this->preference();
    }

    private function member(string $slug): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $user->roles()->attach($role);
        foreach (['internal.send', 'internal.receive', 'patient.send', 'preferences.manage'] as $action) {
            $permission = Permission::firstOrCreate(['slug' => 'messaging.'.$action], ['name' => $action]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $user;
    }

    private function preference(bool $allowed = true, string $useCase = 'care'): void
    {
        $scope = ['patient_id' => $this->patient->id, 'channel' => 'in_app',
            'purpose' => $useCase === 'care' ? 'clinical' : 'transactional', 'scope' => $useCase === 'care' ? 'follow_up' : 'billing'];
        CommunicationConsent::create([...$scope, 'version' => (CommunicationConsent::where($scope)->max('version') ?? 0) + 1,
            'status' => $allowed ? 'granted' : 'withdrawn', 'source' => 'written_form', 'captured_by' => $this->staff->id,
            'captured_at' => now(), 'text_version' => 'v1', 'acknowledged_text' => 'Consent to care messaging.',
            'evidence' => 'Patient signed communication consent.']);
    }

    private function thread(bool $patient = false): int
    {
        return $this->postJson('/api/conversation-threads', [
            'subject' => 'Private discussion', 'user_ids' => [$this->peer->id],
            ...($patient ? ['patient_id' => $this->patient->id, 'use_case' => 'care'] : []),
        ])->assertCreated()->json('data.thread.id');
    }

    private function payload(string $key = 'send-1'): array
    {
        return ['body' => 'Please review the instructions.', 'idempotency_key' => $key];
    }

    private function confirmed(int $thread, array $payload): array
    {
        $token = $this->postJson("/api/conversation-threads/$thread/confirmations", $payload)->assertCreated()->json('data.confirmation_token');

        return [...$payload, 'confirmation_token' => $token, 'send_confirmed' => true, 'consent_acknowledged' => true];
    }

    public function test_staff_exchange_is_participant_scoped_append_only_and_idempotent(): void
    {
        $id = $this->thread();
        $url = "/api/conversation-threads/$id/messages";
        $message = $this->postJson($url, $this->payload())->assertCreated()->assertJsonPath('data.message.delivery_state', 'available')->json('data.message');
        $this->postJson($url, $this->payload())->assertOk()->assertJsonPath('data.message.id', $message['id']);
        $this->postJson($url, [...$this->payload(), 'body' => 'Changed'])->assertConflict();
        Sanctum::actingAs($this->peer);
        $this->postJson($url, $this->payload('reply'))->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.messages');
        $this->patchJson($url.'/'.$message['id'], ['body' => 'Silent change'])->assertNotFound();
        Sanctum::actingAs($this->admin);
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/conversation-threads?search=Private')->assertOk()->assertJsonCount(0, 'data.threads');
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_admin_can_send_to_staff_but_cannot_target_or_access_patients(): void
    {
        $patientThread = $this->thread(true);
        Sanctum::actingAs($this->admin);
        $internal = $this->thread();
        $this->postJson("/api/conversation-threads/$internal/messages", $this->payload())->assertCreated();
        $this->postJson('/api/conversation-threads', ['subject' => 'Forbidden', 'patient_id' => $this->patient->id, 'use_case' => 'care'])->assertForbidden();
        $this->getJson("/api/conversation-threads/$patientThread")->assertNotFound();
        $this->postJson("/api/conversation-threads/$patientThread/messages", $this->payload())->assertNotFound();
        $this->postJson("/api/conversation-threads/$patientThread/confirmations", $this->payload())->assertNotFound();
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/conversation-threads', ['subject' => 'Forbidden participant', 'patient_id' => $this->patient->id, 'use_case' => 'care', 'user_ids' => [$this->admin->id]])->assertForbidden();
        $this->postJson('/api/conversation-threads', ['subject' => 'Staff to admin', 'user_ids' => [$this->admin->id]])->assertForbidden();
    }

    public function test_patient_sends_require_exact_confirmation_and_acknowledgement(): void
    {
        $id = $this->thread(true);
        $url = "/api/conversation-threads/$id/messages";
        $this->postJson($url, $this->payload())->assertUnprocessable();
        $confirmed = $this->confirmed($id, $this->payload());
        $this->postJson($url, [...$confirmed, 'consent_acknowledged' => false])->assertUnprocessable();
        $this->postJson($url, [...$confirmed, 'body' => 'Different message'])->assertUnprocessable();
        $response = $this->postJson($url, $confirmed)->assertCreated()->assertJsonPath('data.message.delivery_state', 'awaiting_portal')
            ->assertJsonPath('data.message.audit_metadata.consent_acknowledged', true);
        $this->assertCount(2, $response->json('data.message.recipient_ids'));
        $this->postJson($url, $confirmed)->assertOk();
        $this->postJson($url, [...$confirmed, 'idempotency_key' => 'reuse-token'])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'conversation_messages', 'action' => 'sent']);
    }

    public function test_confirmation_expires_and_cannot_be_transferred_to_another_actor_or_thread(): void
    {
        $id = $this->thread(true);
        $other = $this->thread(true);
        $payload = $this->confirmed($id, $this->payload());
        $this->postJson("/api/conversation-threads/$other/messages", $payload)->assertUnprocessable();
        Sanctum::actingAs($this->peer);
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertUnprocessable();
        Sanctum::actingAs($this->staff);
        $this->travel(6)->minutes();
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertUnprocessable();
    }

    public function test_consent_is_channel_use_case_specific_and_rechecked_at_send(): void
    {
        $this->postJson('/api/conversation-threads', ['subject' => 'Billing', 'patient_id' => $this->patient->id, 'use_case' => 'billing'])->assertForbidden();
        $id = $this->thread(true);
        $payload = $this->confirmed($id, $this->payload());
        $this->preference(false);
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertForbidden();
        $this->postJson('/api/conversation-threads', ['subject' => 'Care', 'patient_id' => $this->patient->id, 'use_case' => 'care'])->assertForbidden();
        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_explicit_role_grant_and_per_user_denial_are_enforced(): void
    {
        $id = $this->thread(true);
        $payload = $this->confirmed($id, $this->payload());
        MessagingUserPermission::create(['user_id' => $this->staff->id, 'patient_send' => false]);
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertNotFound();
        $this->getJson('/api/conversation-threads')->assertJsonCount(0, 'data.threads');
        MessagingUserPermission::where('user_id', $this->staff->id)->delete();
        $this->staff->roles()->first()->permissions()->detach();
        $this->postJson('/api/conversation-threads', ['subject' => 'Denied', 'patient_id' => $this->patient->id, 'use_case' => 'care'])->assertForbidden();
        MessagingUserPermission::create(['user_id' => $this->staff->id, 'patient_send' => true]);
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertCreated();
    }

    public function test_role_changes_cannot_turn_patient_participants_into_admins(): void
    {
        $id = $this->thread(true);
        $payload = $this->confirmed($id, $this->payload());
        $this->peer->roles()->attach($this->admin->roles()->first());
        $this->postJson("/api/conversation-threads/$id/messages", $payload)->assertForbidden();
        Sanctum::actingAs($this->peer);
        $this->getJson("/api/conversation-threads/$id")->assertNotFound();
    }

    public function test_patient_identity_and_ungranted_staff_cannot_send_or_discover_threads(): void
    {
        $id = $this->thread(true);
        foreach (['patient', 'unprivileged'] as $role) {
            $user = $this->member($role);
            if ($role === 'unprivileged') {
                $user->roles()->first()->permissions()->detach();
            }
            Sanctum::actingAs($user);
            $this->postJson('/api/conversation-threads', ['subject' => 'Denied', 'user_ids' => [$this->staff->id]])->assertForbidden();
            $this->getJson("/api/conversation-threads/$id/messages")->assertNotFound();
            $this->getJson('/api/conversation-threads')->assertJsonCount(0, 'data.threads');
        }
    }

    public function test_cross_tenant_threads_participants_patients_and_preferences_are_inaccessible(): void
    {
        $tenant = Tenant::factory()->create();
        [$foreignUser, $patient, $thread] = app(TenantContext::class)->run($tenant, function () {
            $user = $this->member('dentist');
            $patient = Patient::factory()->create();
            $thread = ConversationThread::create(['created_by' => $user->id, 'subject' => 'Foreign secret', 'use_case' => 'internal']);
            $thread->participants()->create(['user_id' => $user->id]);

            return [$user, $patient, $thread];
        });
        $this->getJson("/api/conversation-threads/$thread->id")->assertNotFound()->assertDontSee('Foreign secret');
        $this->getJson("/api/conversation-threads/$thread->id/messages")->assertNotFound();
        $this->postJson("/api/conversation-threads/$thread->id/messages", $this->payload())->assertNotFound();
        $this->postJson("/api/conversation-threads/$thread->id/confirmations", $this->payload())->assertNotFound();
        $this->postJson('/api/conversation-threads', ['subject' => 'Attack', 'user_ids' => [$foreignUser->id]])->assertUnprocessable();
        $this->postJson('/api/conversation-threads', ['subject' => 'Attack', 'patient_id' => $patient->id, 'use_case' => 'care'])->assertNotFound();
        $this->getJson("/api/patients/$patient->id/messaging-preferences")->assertNotFound();
        $this->putJson("/api/users/$foreignUser->id/messaging-permissions", [])->assertNotFound();
        $this->withHeader('X-Tenant-Id', (string) $tenant->id)->getJson('/api/conversation-threads')->assertForbidden();
    }

    public function test_corrections_and_redactions_append_history_and_hide_redacted_content(): void
    {
        $id = $this->thread();
        $url = "/api/conversation-threads/$id/messages";
        $original = $this->postJson($url, $this->payload())->assertCreated()->json('data.message.id');
        $correction = ['kind' => 'correction', 'supersedes_id' => $original, 'reason' => 'Incorrect instructions', 'body' => 'Correct instructions', 'idempotency_key' => 'correction'];
        Sanctum::actingAs($this->peer);
        $this->postJson($url, $correction)->assertForbidden();
        Sanctum::actingAs($this->staff);
        $this->postJson($url, $correction)->assertCreated();
        $this->postJson($url, ['kind' => 'redaction', 'supersedes_id' => $original, 'reason' => 'Contains private details', 'idempotency_key' => 'redaction'])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data.messages')->assertJsonPath('data.messages.0.body', null)->assertJsonPath('data.messages.0.redacted', true);
        $this->postJson($url, $this->payload())->assertOk()->assertJsonPath('data.message.body', null);
        $this->assertDatabaseHas('conversation_messages', ['id' => $original, 'body' => $this->payload()['body']]);
        $other = $this->thread();
        $this->postJson("/api/conversation-threads/$other/messages", [...$correction, 'idempotency_key' => 'foreign-target'])->assertNotFound();
    }

    public function test_patient_correction_and_redaction_also_require_confirmation(): void
    {
        $id = $this->thread(true);
        $url = "/api/conversation-threads/$id/messages";
        $original = $this->postJson($url, $this->confirmed($id, $this->payload()))->assertCreated()->json('data.message.id');
        $redaction = ['kind' => 'redaction', 'supersedes_id' => $original, 'reason' => 'Entered in error', 'idempotency_key' => 'redact'];
        $this->postJson($url, $redaction)->assertUnprocessable();
        $this->postJson($url, $this->confirmed($id, $redaction))->assertCreated();
    }

    public function test_attachments_sender_spoofing_and_legacy_mutation_are_disabled(): void
    {
        $id = $this->thread();
        foreach (['attachments' => ['file'], 'attachment_ids' => [1], 'sender_id' => $this->admin->id, 'recipient_ids' => [99], 'channel' => 'sms'] as $field => $value) {
            $this->postJson("/api/conversation-threads/$id/messages", [...$this->payload(), $field => $value])->assertUnprocessable();
        }
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/messages/1', $this->payload())->assertGone();
        }
    }

    public function test_permissions_and_patient_preferences_have_separate_management_boundaries(): void
    {
        $url = "/api/users/{$this->peer->id}/messaging-permissions";
        $data = ['internal_send' => null, 'internal_receive' => null, 'patient_send' => false];
        $this->putJson($url, $data)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->putJson($url, $data)->assertOk()->assertJsonPath('data.messaging_permissions.patient_send', false);
        $this->putJson("/api/users/{$this->admin->id}/messaging-permissions", [...$data, 'patient_send' => true])->assertForbidden();
        $preferences = "/api/patients/{$this->patient->id}/messaging-preferences";
        $this->getJson($preferences)->assertForbidden();
        Sanctum::actingAs($this->staff);
        $this->getJson($preferences)->assertOk()->assertJsonPath('data.preferences.0.allowed', true)->assertJsonPath('data.preferences.0.use_case', 'care');
        $this->putJson($preferences, ['channel' => 'in_app', 'use_case' => 'care', 'allowed' => false, 'evidence' => 'Patient withdrew consent',
            'source' => 'in_person', 'text_version' => 'v1', 'acknowledged_text' => 'I withdraw care messaging consent.'])->assertOk()
            ->assertJsonPath('data.preference.allowed', false)->assertJsonPath('data.preference.status', 'withdrawn')->assertJsonPath('data.preference.version', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consent_withdrawn']);
    }

    public function test_durable_send_limit_does_not_prevent_idempotent_replays(): void
    {
        $id = $this->thread();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/conversation-threads/$id/messages", $this->payload('send-'.$i))->assertCreated();
        }
        $this->postJson("/api/conversation-threads/$id/messages", $this->payload('excess'))->assertStatus(429);
        $this->postJson("/api/conversation-threads/$id/messages", $this->payload('send-0'))->assertOk();
        $this->assertDatabaseCount('conversation_messages', 20);
    }

    public function test_database_rejects_silent_message_update(): void
    {
        $id = $this->thread();
        $this->postJson("/api/conversation-threads/$id/messages", $this->payload())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('conversation_messages')->update(['body' => 'Tampered']);
    }

    public function test_database_rejects_silent_message_deletion(): void
    {
        $id = $this->thread();
        $this->postJson("/api/conversation-threads/$id/messages", $this->payload())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('conversation_messages')->delete();
    }

    public function test_database_rejects_cross_tenant_participant_injection(): void
    {
        $id = $this->thread();
        $tenant = Tenant::factory()->create();
        $foreign = User::factory()->forTenant($tenant)->create();
        $this->expectException(QueryException::class);
        DB::table('conversation_participants')->insert(['tenant_id' => app(TenantContext::class)->id(), 'thread_id' => $id, 'user_id' => $foreign->id]);
    }
}
