<?php

namespace Tests\Feature;

use App\Communications\DeliveryException;
use App\Communications\SmtpProvider;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentDecision;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClinicNotificationService;
use App\Services\CommunicationConfigurationService;
use App\Services\PatientContactService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeNotificationProvider;
use Tests\TestCase;

class CommunicationConsentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeNotificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeNotificationProvider;
        $this->app->instance(SmtpProvider::class, $this->provider);
    }

    private function within(callable $callback): void
    {
        app(TenantContext::class)->run(Tenant::where('slug', Tenant::DEFAULT_SLUG)->firstOrFail(), $callback(...));
    }

    private function queued(): array
    {
        $actor = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $actor->roles()->attach($role);
        app(CommunicationConfigurationService::class)->configure(['channels' => [
            'email' => ['provider' => 'smtp', 'sender' => 'clinic@example.test', 'host' => 'smtp.example.test', 'port' => 465, 'username' => 'test', 'password' => 'secret'],
        ], 'events' => ['payment.recorded' => ['channels' => ['email']]]], true);
        $payment = Payment::factory()->create();
        $patient = $payment->invoice->patient;
        $contact = app(PatientContactService::class)->create($patient, ['channel' => 'email', 'address' => 'patient@example.test', 'role' => 'patient', 'authorized_events' => ['payment.recorded']], $actor);
        app(PatientContactService::class)->verify($patient, $contact->id, 'in_person', $actor);
        $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);

        return [$actor, $patient, $notification];
    }

    private function consent($actor, $patient, string $status = 'granted', string $scope = 'payment_receipts'): CommunicationConsent
    {
        return CommunicationConsent::create(['patient_id' => $patient->id, 'purpose' => 'transactional', 'scope' => $scope, 'channel' => 'email',
            'status' => $status, 'source' => 'written_form', 'captured_by' => $actor->id, 'captured_at' => now('UTC'),
            'version' => (CommunicationConsent::where('patient_id', $patient->id)->where('scope', $scope)->max('version') ?? 0) + 1,
            'text_version' => 'receipts-v1', 'acknowledged_text' => 'I agree to emailed payment receipts.', 'evidence' => 'Signed form.']);
    }

    public function test_verified_contact_is_not_patient_consent_and_missing_consent_suppresses_provider_call(): void
    {
        $this->within(function () {
            [, , $notification] = $this->queued();
            $this->assertDatabaseCount('communication_consents', 0);
            $this->artisan('communications:work')->assertSuccessful();
            $delivery = $notification->deliveries()->firstOrFail();
            $this->assertSame('suppressed', $delivery->status);
            $this->assertSame('consent_missing', $delivery->failure_reason);
            $this->assertCount(0, $this->provider->messages);
            $this->assertDatabaseHas('communication_consent_decisions', ['id' => $delivery->consent_decision_id, 'allowed' => false, 'reason' => 'consent_missing']);
            $this->assertDatabaseHas('audit_logs', ['action' => 'consent_blocked']);
        });
    }

    public function test_dispatch_checks_latest_consent_after_queueing_and_retains_exact_granted_version(): void
    {
        $this->within(function () {
            [$actor, $patient, $notification] = $this->queued();
            $this->consent($actor, $patient, 'declined');
            $consent = $this->consent($actor, $patient);
            $this->artisan('communications:work')->assertSuccessful();
            $delivery = $notification->deliveries()->firstOrFail();
            $this->assertSame('accepted', $delivery->status);
            $decision = CommunicationConsentDecision::findOrFail($delivery->consent_decision_id);
            $this->assertSame($consent->id, $decision->consent_id);
            $this->assertSame(2, $decision->snapshot['version']);
            $this->consent($actor, $patient, 'withdrawn');
            $this->assertSame('granted', $decision->fresh()->snapshot['status']);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
        });
    }

    public function test_withdrawal_before_dispatch_blocks_already_queued_delivery(): void
    {
        $this->within(function () {
            [$actor, $patient, $notification] = $this->queued();
            $this->consent($actor, $patient);
            $this->consent($actor, $patient, 'withdrawn');
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('consent_withdrawn', $notification->deliveries()->firstOrFail()->failure_reason);
            $this->assertCount(0, $this->provider->messages);
        });
    }

    public function test_retry_rechecks_consent_and_keeps_prior_attempt_decision(): void
    {
        $this->within(function () {
            [$actor, $patient, $notification] = $this->queued();
            $this->consent($actor, $patient);
            $this->provider->errors[] = new DeliveryException('provider_unavailable', retryable: true);
            $this->artisan('communications:work')->assertSuccessful();
            $this->consent($actor, $patient, 'revoked');
            $this->travel(2)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $delivery = $notification->deliveries()->firstOrFail();
            $this->assertSame('consent_revoked', $delivery->failure_reason);
            $this->assertSame(2, CommunicationConsentDecision::where('resource_id', $delivery->id)->count());
            $this->assertSame(1, $delivery->attempts);
        });
    }

    public function test_billing_consent_does_not_authorize_receipts(): void
    {
        $this->within(function () {
            [$actor, $patient, $notification] = $this->queued();
            $this->consent($actor, $patient, scope: 'billing');
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('consent_missing', $notification->deliveries()->firstOrFail()->failure_reason);
            $this->assertCount(0, $this->provider->messages);
        });
    }
}
