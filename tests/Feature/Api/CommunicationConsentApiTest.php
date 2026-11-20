<?php

namespace Tests\Feature\Api;

use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentDecision;
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

class CommunicationConsentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $role = Role::create(['name' => 'Consent staff', 'slug' => 'consent-staff']);
        $this->staff->roles()->attach($role);
        foreach (['preferences.manage', 'patient.send', 'patient.view'] as $action) {
            $role->permissions()->attach(Permission::create(['name' => $action, 'slug' => 'messaging.'.$action]));
        }
        $this->patient = Patient::factory()->create();
        Sanctum::actingAs($this->staff);
    }

    private function url(): string
    {
        return '/api/patients/'.$this->patient->id.'/communication-consents';
    }

    private function evidence(string $status = 'granted', string $channel = 'in_app'): array
    {
        return ['channel' => $channel, 'purpose' => 'clinical', 'scope' => 'follow_up', 'status' => $status,
            'source' => 'written_form', 'text_version' => 'care-policy-v2',
            'acknowledged_text' => 'I agree to clinical follow-up messages through this channel.',
            'evidence' => 'Signed patient consent form REF-23.'];
    }

    private function current(string $channel = 'in_app', string $purpose = 'clinical', string $scope = 'follow_up')
    {
        return $this->getJson($this->url().'/current?'.http_build_query(compact('purpose', 'scope', 'channel')));
    }

    public function test_each_state_appends_versioned_evidence_with_server_attribution_and_audit(): void
    {
        $this->current()->assertOk()->assertJsonPath('data.consent.reason', 'consent_missing');
        foreach (['granted', 'declined', 'withdrawn', 'revoked', 'granted'] as $index => $status) {
            $this->postJson($this->url(), $this->evidence($status))->assertCreated()
                ->assertJsonPath('data.consent.version', $index + 1)
                ->assertJsonPath('data.consent.captured_by', $this->staff->id)
                ->assertJsonPath('data.consent.capture_actor_type', 'staff')
                ->assertJsonPath('data.consent.text_version', 'care-policy-v2');
            $this->current()->assertOk()->assertJsonPath('data.consent.allowed', $status === 'granted')
                ->assertJsonPath('data.consent.reason', 'consent_'.$status)->assertJsonPath('data.consent.version', $index + 1);
        }
        $this->getJson($this->url().'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 5);
        $this->assertDatabaseHas('communication_consents', ['version' => 1, 'status' => 'granted', 'evidence' => 'Signed patient consent form REF-23.']);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'communication_consents', 'action' => 'consent_withdrawn', 'actor_id' => $this->staff->id]);
    }

    public function test_purpose_scope_and_channel_are_independent(): void
    {
        foreach (['in_app', 'email', 'sms'] as $channel) {
            $this->postJson($this->url(), $this->evidence(channel: $channel))->assertCreated()->assertJsonPath('data.consent.version', 1);
            $this->current($channel)->assertJsonPath('data.consent.allowed', true);
            $this->current($channel, 'transactional', 'appointments')->assertJsonPath('data.consent.reason', 'consent_missing');
            $this->current($channel, 'transactional', 'payment_receipts')->assertJsonPath('data.consent.reason', 'consent_missing');
            $this->current($channel, 'general', 'non_essential')->assertJsonPath('data.consent.reason', 'consent_missing');
        }
        $this->postJson($this->url(), $this->evidence('withdrawn', 'sms'))->assertCreated();
        $this->current('sms')->assertJsonPath('data.consent.allowed', false);
        $this->current('email')->assertJsonPath('data.consent.allowed', true);
        $this->postJson($this->url(), [...$this->evidence(), 'purpose' => 'general'])->assertUnprocessable();
    }

    public function test_only_explicitly_permitted_staff_can_record_and_review_consent(): void
    {
        $role = $this->staff->roles()->first();
        $permission = Permission::where('slug', 'messaging.preferences.manage')->firstOrFail();
        $role->permissions()->detach($permission->id);
        $role->permissions()->attach(Permission::create(['name' => 'Wildcard', 'slug' => '*']));
        $this->postJson($this->url(), $this->evidence())->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
        $role->permissions()->attach($permission->id);
        foreach (['admin', 'patient'] as $slug) {
            $special = Role::create(['name' => $slug, 'slug' => $slug]);
            $this->staff->roles()->attach($special);
            $this->postJson($this->url(), $this->evidence())->assertForbidden();
            $this->current()->assertForbidden();
            $this->staff->roles()->detach($special);
        }
        $this->assertDatabaseCount('communication_consents', 0);
    }

    public function test_capture_fields_and_acknowledged_policy_are_required_and_cannot_be_spoofed(): void
    {
        foreach (['text_version', 'acknowledged_text', 'source', 'evidence'] as $field) {
            $data = $this->evidence();
            unset($data[$field]);
            $this->postJson($this->url(), $data)->assertUnprocessable();
        }
        foreach (['captured_by' => 999, 'captured_at' => '2020-01-01', 'capture_actor_type' => 'patient', 'captured_by_patient_id' => $this->patient->id, 'version' => 9, 'source' => 'legacy_import', 'channel' => 'whatsapp', 'status' => 'pending'] as $field => $value) {
            $this->postJson($this->url(), [...$this->evidence(), $field => $value])->assertUnprocessable();
        }
        foreach (['purpose', 'scope', 'channel'] as $field) {
            $this->postJson($this->url(), [...$this->evidence(), $field => ['invalid']])->assertUnprocessable();
        }
        $this->assertDatabaseCount('communication_consents', 0);
    }

    public function test_send_confirmation_cannot_create_patient_consent_and_block_reason_is_explicit(): void
    {
        $payload = ['subject' => 'Care', 'patient_id' => $this->patient->id, 'use_case' => 'care', 'send_confirmed' => true, 'consent_acknowledged' => true];
        $this->postJson('/api/conversation-threads', $payload)->assertForbidden()->assertJsonPath('code', 'COMMUNICATION_CONSENT_BLOCKED')
            ->assertJsonPath('errors.consent.reason', 'consent_missing');
        $this->assertDatabaseCount('communication_consents', 0);
        $this->postJson($this->url(), $this->evidence('declined'))->assertCreated();
        $this->postJson('/api/conversation-threads', $payload)->assertForbidden()->assertJsonPath('errors.consent.reason', 'consent_declined')
            ->assertJsonPath('errors.consent.version', 1);
    }

    public function test_send_records_exact_latest_patient_consent_separately_from_staff_confirmation(): void
    {
        $this->postJson($this->url(), $this->evidence())->assertCreated();
        $thread = $this->postJson('/api/conversation-threads', ['subject' => 'Care', 'patient_id' => $this->patient->id, 'use_case' => 'care'])->assertCreated()->json('data.thread.id');
        $payload = ['body' => 'Care instructions', 'idempotency_key' => 'care-1'];
        $confirmation = $this->postJson("/api/conversation-threads/$thread/confirmations", $payload)->assertCreated();
        $this->postJson($this->url(), [...$this->evidence(), 'text_version' => 'care-policy-v3'])->assertCreated();
        $sent = $this->postJson("/api/conversation-threads/$thread/messages", [...$payload,
            'confirmation_token' => $confirmation->json('data.confirmation_token'), 'send_confirmed' => true, 'consent_acknowledged' => true])->assertCreated()
            ->assertJsonPath('data.message.audit_metadata.patient_consent.version', 2)
            ->assertJsonPath('data.message.audit_metadata.patient_consent.text_version', 'care-policy-v3');
        $decisionId = $sent->json('data.message.audit_metadata.consent_decision_id');
        $this->assertDatabaseHas('communication_consent_decisions', ['id' => $decisionId, 'allowed' => true, 'reason' => 'consent_granted']);
        $this->postJson($this->url(), $this->evidence('revoked'))->assertCreated();
        $this->getJson("/api/conversation-threads/$thread/messages")->assertOk()->assertJsonPath('data.messages.0.audit_metadata.patient_consent.version', 2);
        $this->postJson("/api/conversation-threads/$thread/messages", [...$payload, 'idempotency_key' => 'new-send', 'send_confirmed' => true, 'consent_acknowledged' => true])
            ->assertForbidden()->assertJsonPath('errors.consent.reason', 'consent_revoked');
        $this->assertSame(2, CommunicationConsentDecision::findOrFail($decisionId)->snapshot['version']);
    }

    public function test_operational_basis_is_separate_and_never_silently_overrides_consent(): void
    {
        $this->postJson($this->url(), $this->evidence('declined'))->assertCreated();
        $url = '/api/patients/'.$this->patient->id.'/operational-notice-bases';
        foreach (['emergency', 'legal_obligation'] as $basis) {
            $this->postJson($url, ['channel' => 'in_app', 'basis' => $basis, 'reason' => 'Specific operational notice', 'authority_reference' => 'Reviewed authority REF-1'])
                ->assertCreated()->assertJsonPath('data.dispatch_authorized', false);
        }
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->current()->assertJsonPath('data.consent.reason', 'consent_declined');
        $this->assertDatabaseCount('communication_consents', 1);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'operational_notice_bases', 'action' => 'basis_recorded']);
    }

    public function test_tenant_context_protects_current_history_and_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Patient::factory()->create());
        $url = '/api/patients/'.$foreign->id.'/communication-consents';
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'/current?channel=in_app&purpose=clinical&scope=follow_up')->assertNotFound();
        $this->postJson($url, $this->evidence())->assertNotFound();
        $this->postJson('/api/patients/'.$foreign->id.'/operational-notice-bases', [])->assertNotFound();
        $this->withHeader('X-Tenant-Id', (string) $tenant->id)->getJson($this->url())->assertForbidden();
        $this->assertDatabaseCount('communication_consents', 0);
    }

    public function test_database_prevents_evidence_overwrite(): void
    {
        $this->postJson($this->url(), $this->evidence())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('communication_consents')->update(['status' => 'withdrawn']);
    }

    public function test_database_prevents_evidence_deletion(): void
    {
        $this->postJson($this->url(), $this->evidence())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('communication_consents')->delete();
    }

    public function test_database_prevents_foreign_patient_evidence_injection(): void
    {
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Patient::factory()->create());
        $this->expectException(QueryException::class);
        CommunicationConsent::create([...$this->evidence(), 'patient_id' => $foreign->id,
            'captured_by' => $this->staff->id, 'captured_at' => now(), 'version' => 1]);
    }

    public function test_database_preserves_independent_send_decision_history(): void
    {
        $consent = $this->postJson($this->url(), $this->evidence())->assertCreated()->json('data.consent.id');
        $decision = CommunicationConsentDecision::create([
            'patient_id' => $this->patient->id, 'consent_id' => $consent, 'purpose' => 'clinical', 'scope' => 'follow_up', 'channel' => 'in_app',
            'allowed' => true, 'reason' => 'consent_granted', 'snapshot' => ['consent_id' => $consent, 'version' => 1],
            'resource_type' => 'conversation_threads', 'resource_id' => 1, 'actor_id' => $this->staff->id, 'decided_at' => now('UTC'),
        ]);
        $this->expectException(QueryException::class);
        DB::table('communication_consent_decisions')->where('id', $decision->id)->update(['allowed' => false]);
    }

    public function test_database_preserves_operational_basis_history(): void
    {
        $url = '/api/patients/'.$this->patient->id.'/operational-notice-bases';
        $this->postJson($url, ['channel' => 'sms', 'basis' => 'emergency', 'reason' => 'Specific notice', 'authority_reference' => 'Reviewed reference'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('operational_notice_bases')->delete();
    }

    public function test_future_patient_actor_storage_cannot_attribute_another_patients_consent(): void
    {
        $data = [...$this->evidence(), 'patient_id' => $this->patient->id, 'capture_actor_type' => 'patient',
            'captured_by_patient_id' => $this->patient->id, 'source' => 'patient_portal', 'captured_at' => now('UTC'), 'version' => 1];
        $record = CommunicationConsent::create($data);
        $this->assertSame('patient', $record->capture_actor_type);
        $this->assertNull($record->captured_by);
        $other = Patient::factory()->create();
        $this->expectException(QueryException::class);
        CommunicationConsent::create([...$data, 'captured_by_patient_id' => $other->id, 'version' => 2]);
    }

    public function test_removing_staff_membership_preserves_consent_capture_identity_and_history(): void
    {
        $this->postJson($this->url(), $this->evidence())->assertCreated();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin', 'slug' => 'admin']));
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/users/'.$this->staff->id)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->staff->id]);
        $this->assertDatabaseMissing('tenant_user', ['user_id' => $this->staff->id]);
        $this->assertDatabaseHas('communication_consents', ['captured_by' => $this->staff->id, 'version' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership_removed', 'resource_id' => (string) $this->staff->id]);
    }
}
