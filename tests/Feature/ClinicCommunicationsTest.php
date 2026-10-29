<?php

namespace Tests\Feature;

use App\Communications\DeliveryException;
use App\Communications\SafeTemplate;
use App\Communications\SmtpProvider;
use App\Communications\TwilioProvider;
use App\Models\AuditLog;
use App\Models\ClinicNotification;
use App\Models\CommunicationConfiguration;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingWorkflow;
use App\Services\ClinicNotificationService;
use App\Services\CommunicationConfigurationService;
use App\Services\NotificationDeliveryService;
use App\Services\PatientContactService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeNotificationProvider;
use Tests\TestCase;

class ClinicCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private FakeNotificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeNotificationProvider;
        $this->app->instance(SmtpProvider::class, $this->provider);
        $this->app->instance(TwilioProvider::class, $this->provider);
    }

    public function test_manual_payment_queues_both_channels_once_and_does_not_send_in_request(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $invoice = Invoice::factory()->issued()->create();
            $patient = $invoice->patient;
            $this->contact($patient, $actor, 'patient@example.test');
            $this->contact($patient, $actor, '+15555550101', channel: 'sms');
            $payload = ['invoice_id' => $invoice->id, 'amount' => '25.00', 'currency' => 'USD', 'payment_method' => 'cash', 'idempotency_key' => 'same-event'];
            $payment = app(BillingWorkflow::class)->recordPayment($payload, $actor);
            app(BillingWorkflow::class)->recordPayment($payload, $actor);
            app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->assertCount(0, $this->provider->messages);
            $this->assertDatabaseCount('clinic_notifications', 1);
            $this->assertDatabaseCount('notification_deliveries', 2);
            $notification = ClinicNotification::firstOrFail();
            $this->assertSame($actor->id, $notification->actor_id);
            $this->assertSame('staff', $notification->trigger);
            $this->assertSame('USD 75.00', $notification->variables['balance']);
            $this->assertDatabaseHas('audit_logs', ['action' => 'queued', 'resource_type' => 'clinic_notifications', 'actor_id' => $actor->id]);
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->assertSame(2, NotificationDelivery::where('status', 'accepted')->count());
            $this->assertStringContainsString('USD 25.00', $this->provider->messages[0]['message']->body);
            $this->assertStringContainsString('USD 75.00', $this->provider->messages[0]['message']->body);
            $this->assertSame('clinic@example.test', $this->provider->messages[0]['settings']['sender']);
        });
    }

    public function test_only_verified_event_authorized_patient_related_contacts_and_billing_staff_receive_copies(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $copy = $this->actor(Role::BILLING);
            $this->configure([$copy->id]);
            $payment = Payment::factory()->create();
            $patient = $payment->invoice->patient;
            $this->contact($patient, $actor, 'patient@example.test');
            $this->contact($patient, $actor, 'unverified@example.test', verified: false);
            $this->contact($patient, $actor, 'payer@example.test', role: 'billing_copy');
            $this->contact($patient, $actor, 'wrong-event@example.test', events: ['payment_link.created']);
            $this->contact($patient, $actor, 'patient@example.test', role: 'billing_copy'); // Deduplicated.
            $revoked = $this->contact($patient, $actor, 'revoked@example.test');
            app(PatientContactService::class)->revoke($patient, $revoked->id, $actor);
            $this->contact(Patient::factory()->create(), $actor, 'unrelated@example.test');
            $patient->update(['emergency_contact_phone' => '+15555550102']);
            $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->assertEqualsCanonicalizing(['patient@example.test', 'payer@example.test', strtolower($copy->email)], $notification->deliveries->pluck('address')->all());
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(3, $this->provider->messages);
            $serialized = AuditLog::all()->toJson();
            $this->assertStringNotContainsString('patient@example.test', $serialized);
            $this->assertStringNotContainsString($patient->firstName, $serialized);
        });
    }

    public function test_copies_are_configured_per_event_and_do_not_receive_checkout_links(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $copy = $this->actor(Role::BILLING);
            $settings = $this->settings();
            $settings['events']['payment_link.created']['copy_user_ids'] = [$copy->id];
            app(CommunicationConfigurationService::class)->configure($settings, true);
            $invoice = Invoice::factory()->issued()->create();
            $this->contact($invoice->patient, $actor, 'patient@example.test');
            $this->contact($invoice->patient, $actor, 'payer@example.test', role: 'billing_copy');
            $link = $this->link($invoice);
            $notification = app(ClinicNotificationService::class)->paymentLinkCreated($link->id, $actor->id);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(3, $this->provider->messages);
            foreach ($this->provider->messages as $sent) {
                if ($sent['message']->to === 'patient@example.test') {
                    $this->assertStringContainsString($link->checkout_url, $sent['message']->body);
                } else {
                    $this->assertStringNotContainsString($link->checkout_url, $sent['message']->body);
                }
            }
            $link->update(['status' => 'cancelled']);
            $payment = Payment::factory()->for($invoice)->create();
            $receipt = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->assertNotContains($copy->id, $receipt->deliveries->pluck('copy_user_id')->all());
            $this->assertSame('queued', $notification->selection_status);
        });
    }

    public function test_tenant_senders_contacts_sources_outbox_and_history_are_isolated(): void
    {
        $clinicA = Tenant::where('slug', Tenant::DEFAULT_SLUG)->firstOrFail();
        $clinicB = Tenant::factory()->create();
        $make = function (string $sender) {
            $actor = $this->actor();
            $this->configure(sender: $sender);
            $payment = Payment::factory()->create();
            $contact = $this->contact($payment->invoice->patient, $actor, $sender);
            $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);

            return [$actor, $payment, $contact, $notification, $notification->deliveries()->firstOrFail()];
        };
        [$actorA, $paymentA, $contactA, $noticeA, $deliveryA] = $this->within(fn () => $make('a@example.test'), $clinicA);
        [$actorB, $paymentB, $contactB, $noticeB, $deliveryB] = $this->within(fn () => $make('b@example.test'), $clinicB);
        $this->within(function () use ($paymentB, $actorA, $noticeA, $noticeB, $deliveryB, $clinicA) {
            $this->assertNull(ClinicNotification::find($noticeB->id));
            $this->assertSame([$noticeA->id], ClinicNotification::pluck('id')->all());
            app(NotificationDeliveryService::class)->deliver($clinicA->id, $deliveryB->id);
            $this->assertCount(0, $this->provider->messages);
            try {
                app(ClinicNotificationService::class)->paymentRecorded($paymentB->id, $actorA->id);
                $this->fail('Foreign sources must be inaccessible.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }, $clinicA);
        Sanctum::actingAs($actorA);
        $this->getJson('/api/invoices/'.$paymentB->invoice_id.'/communications')->assertNotFound();
        $this->postJson('/api/patients/'.$paymentA->invoice->patient_id.'/contacts/'.$contactB->id.'/verify', ['verification_method' => 'in_person'])->assertNotFound();
        $this->getJson('/api/invoices/'.$paymentA->invoice_id.'/communications')->assertOk()
            ->assertJsonPath('data.0.id', $noticeA->id)->assertJsonMissingPath('data.0.variables')->assertJsonMissingPath('data.0.deliveries.0.address');
        $this->artisan('communications:work')->assertSuccessful();
        $this->assertCount(2, $this->provider->messages);
        foreach ($this->provider->messages as $sent) {
            $this->assertSame($sent['message']->to, $sent['settings']['sender']);
        }
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->requireId();
    }

    public function test_configuration_rejects_foreign_unverified_and_non_billing_internal_copy_users(): void
    {
        $foreign = Tenant::factory()->create();
        $foreignUser = $this->within(fn () => $this->actor(), $foreign);
        $this->within(function () use ($foreignUser) {
            foreach ([$foreignUser, User::factory()->unverified()->create(), $this->actor(Role::RECEPTIONIST)] as $user) {
                try {
                    $this->configure([$user->id]);
                    $this->fail('Unrelated or unverified users cannot be copy recipients.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('copy_user_ids', $error->errors());
                }
            }
            $this->assertDatabaseCount('communication_configurations', 0);
        });
    }

    public function test_contact_and_copy_revocation_are_rechecked_before_send(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $copy = $this->actor(Role::BILLING);
            $this->configure([$copy->id]);
            $payment = Payment::factory()->create();
            $contact = $this->contact($payment->invoice->patient, $actor, 'patient@example.test');
            app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            app(PatientContactService::class)->revoke($payment->invoice->patient, $contact->id, $actor);
            $copy->tenants()->detach(app(TenantContext::class)->requireId());
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(0, $this->provider->messages);
            $this->assertSame(2, NotificationDelivery::where('status', 'suppressed')->where('failure_reason', 'recipient_no_longer_authorized')->count());
        });
    }

    public function test_revoked_actor_and_changed_configuration_stop_queued_delivery(): void
    {
        $this->within(function () {
            [$actor, $payment, $delivery] = $this->queued();
            $actor->tenants()->detach(app(TenantContext::class)->requireId());
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('trigger_no_longer_authorized', $delivery->refresh()->failure_reason);
            $actor->tenants()->attach(app(TenantContext::class)->requireId());
            $actor->roles()->attach(Role::where('slug', Role::ADMIN)->firstOrFail());
            $next = Payment::factory()->for($payment->invoice)->create();
            $notification = app(ClinicNotificationService::class)->paymentRecorded($next->id, $actor->id);
            $this->configure(sender: 'new-sender@example.test');
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('configuration_changed', $notification->deliveries()->firstOrFail()->failure_reason);
            $this->assertCount(0, $this->provider->messages);
        });
    }

    public function test_retry_backoff_exhaustion_and_safe_failure_history(): void
    {
        $this->within(function () {
            [$actor, $payment, $delivery] = $this->queued();
            $this->provider->errors = array_fill(0, 5, new DeliveryException('provider_rate_limited', retryable: true));
            $worker = app(NotificationDeliveryService::class);
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $worker->deliver($delivery->tenant_id, $delivery->id);
                $this->assertSame($attempt, $delivery->refresh()->attempts);
                $this->assertSame($attempt === 5 ? 'failed' : 'retry', $delivery->status);
                $worker->deliver($delivery->tenant_id, $delivery->id);
                $this->assertCount($attempt, $this->provider->messages);
                $this->travel(61)->minutes();
            }
            $this->assertSame('provider_rate_limited', $delivery->failure_reason);
            $this->assertSame(4, AuditLog::where('resource_type', 'notification_deliveries')->where('action', 'retry.provider_rate_limited')->count());
            $this->assertSame(1, AuditLog::where('action', 'failed.provider_rate_limited')->count());
        });
    }

    public function test_retry_can_succeed_without_resending_accepted_messages(): void
    {
        $this->within(function () {
            [, , $delivery] = $this->queued();
            $this->provider->errors[] = new DeliveryException('smtp_connection_failed', retryable: true);
            $this->artisan('communications:work')->assertSuccessful();
            $this->travel(2)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(2, $this->provider->messages);
            $this->assertSame('accepted', $delivery->refresh()->status);
            $this->assertNull($delivery->failure_reason);
            $this->assertNotNull($delivery->accepted_at);
            $this->assertSame($this->provider->messages[0]['message']->operationKey, $this->provider->messages[1]['message']->operationKey);
        });
    }

    public function test_ambiguous_provider_errors_and_interrupted_workers_never_automatically_resend(): void
    {
        $this->within(function () {
            [, $payment, $delivery] = $this->queued();
            $this->provider->errors[] = new \RuntimeException('secret token patient@example.test');
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('unknown', $delivery->refresh()->status);
            $this->assertSame('acceptance_unknown', $delivery->failure_reason);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            $delivery->update(['status' => 'processing', 'attempted_at' => now()->subMinutes(6)]);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('worker_interrupted', $delivery->refresh()->failure_reason);
            $this->assertCount(1, $this->provider->messages);
            $this->assertStringNotContainsString('secret token', AuditLog::all()->toJson());
        });
    }

    public function test_no_eligible_contacts_is_recorded_without_falling_back_to_emergency_or_staff_addresses(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $payment = Payment::factory()->create();
            $payment->invoice->patient->update(['emergency_contact_phone' => '+15555550101']);
            $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->assertSame('no_eligible_recipients', $notification->selection_status);
            $this->assertDatabaseCount('notification_deliveries', 0);
            $this->assertDatabaseHas('audit_logs', ['action' => 'no_eligible_recipients', 'resource_type' => 'clinic_notifications']);
        });
    }

    public function test_payment_receipt_and_outbox_commit_or_rollback_together(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $invoice = Invoice::factory()->issued()->create();
            $this->contact($invoice->patient, $actor, 'patient@example.test');
            try {
                DB::transaction(function () use ($invoice, $actor) {
                    app(BillingWorkflow::class)->recordPayment(['invoice_id' => $invoice->id, 'amount' => '25.00', 'currency' => 'USD', 'payment_method' => 'cash', 'idempotency_key' => 'rolled-back'], $actor);
                    throw new \RuntimeException('abort');
                });
            } catch (\RuntimeException) {
                $this->assertDatabaseCount('payments', 0);
                $this->assertDatabaseCount('clinic_notifications', 0);
                $this->assertDatabaseCount('notification_deliveries', 0);
                $this->assertSame('0.00', $invoice->refresh()->amount_paid);
                $this->assertCount(0, $this->provider->messages);
            }
        });
    }

    public function test_verified_contact_api_requires_authority_and_rejects_forged_verification(): void
    {
        $actor = $this->actor();
        Sanctum::actingAs($actor);
        $patient = Patient::factory()->create();
        $path = '/api/patients/'.$patient->id.'/contacts';
        $data = ['channel' => 'email', 'address' => 'patient@example.test', 'role' => 'patient', 'authorized_events' => ['payment.recorded']];
        $this->postJson($path, [...$data, 'verified_at' => now()->toISOString()])->assertUnprocessable();
        $contact = $this->postJson($path, $data)->assertCreated()->assertJsonPath('data.contact.verified_at', null)->json('data.contact.id');
        $this->postJson($path.'/'.$contact.'/verify', ['verification_method' => 'in_person'])->assertOk();
        $this->assertDatabaseHas('patient_contacts', ['id' => $contact, 'verified_by' => $actor->id, 'verification_method' => 'in_person']);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'patient_contacts', 'action' => 'verified', 'actor_id' => $actor->id]);
        $unprivileged = User::factory()->create();
        Sanctum::actingAs($unprivileged);
        $this->postJson($path.'/'.$contact.'/verify', ['verification_method' => 'in_person'])->assertForbidden();
        $this->getJson($path)->assertForbidden();
    }

    public function test_sensitive_storage_is_encrypted_and_template_variables_are_literal(): void
    {
        $this->within(function () {
            [$actor, $payment, $delivery] = $this->queued();
            $this->assertStringNotContainsString('patient@example.test', DB::table('notification_deliveries')->value('address'));
            $this->assertStringNotContainsString('patient@example.test', DB::table('patient_contacts')->value('address'));
            $this->assertStringNotContainsString('password-secret', DB::table('communication_configurations')->value('settings'));
            $this->assertStringNotContainsString('patient_name', DB::table('clinic_notifications')->value('variables'));
            $template = app(SafeTemplate::class);
            $this->assertSame('<script>{{balance}}</script>', $template->render('{{patient_name}}', ['patient_name' => '<script>{{balance}}</script>']));
            foreach (['{{password}}', '{{patient.name}}', '{!! patient_name !!}', '<?php echo 1; ?>', '<a href="{{payment_link}}">Pay</a>'] as $unsafe) {
                try {
                    $template->validate($unsafe);
                    $this->fail('Unsafe templates must be rejected.');
                } catch (\InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        });
    }

    public function test_inactive_tenants_and_expired_links_do_not_send(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $invoice = Invoice::factory()->issued()->create();
            $this->contact($invoice->patient, $actor, 'patient@example.test');
            $link = $this->link($invoice);
            $notification = app(ClinicNotificationService::class)->paymentLinkCreated($link->id, $actor->id);
            $link->update(['expires_at' => now()->subSecond()]);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('payment_link_no_longer_active', $notification->deliveries()->firstOrFail()->failure_reason);
            $payment = Payment::factory()->create();
            $this->contact($payment->invoice->patient, $actor, 'second@example.test');
            $next = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            Tenant::whereKey(app(TenantContext::class)->requireId())->update(['active' => false]);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(0, $this->provider->messages);
            $this->assertSame('pending', $next->deliveries()->firstOrFail()->status);
        });
    }

    public function test_raw_cross_tenant_contact_links_are_rejected_by_database(): void
    {
        $foreign = Tenant::factory()->create();
        $patient = $this->within(fn () => Patient::factory()->create(), $foreign);
        $this->expectException(QueryException::class);
        DB::table('patient_contacts')->insert(['tenant_id' => Tenant::where('slug', Tenant::DEFAULT_SLUG)->value('id'),
            'patient_id' => $patient->id, 'channel' => 'email', 'address' => 'ciphertext', 'role' => 'patient', 'authorized_events' => '[]']);
    }

    public function test_checkout_creation_and_refresh_queue_one_notice_then_one_receipt(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $provider = new \Tests\Fakes\FakePaymentProvider;
            $this->app->instance(\Tests\Fakes\FakePaymentProvider::class, $provider);
            config(['billing.providers.handypay' => \Tests\Fakes\FakePaymentProvider::class]);
            PaymentProviderConfiguration::create(['provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_secret'],
                'success_url' => 'https://clinic.test/ok', 'cancel_url' => 'https://clinic.test/cancel', 'enabled' => true]);
            $invoice = Invoice::factory()->issued()->create();
            $this->contact($invoice->patient, $actor, 'patient@example.test');
            $workflow = app(\App\Services\PaymentLinkWorkflow::class);
            $link = $workflow->create($invoice, ['idempotency_key' => 'link-notice'], $actor);
            $workflow->create($invoice, ['idempotency_key' => 'link-notice'], $actor);
            $this->assertDatabaseCount('clinic_notifications', 1);
            $this->assertDatabaseCount('notification_deliveries', 1);
            $this->assertSame('payment_link.created', ClinicNotification::firstOrFail()->event);
            $this->assertCount(0, $this->provider->messages);
            $lookupActor = $this->actor('checkout-reconciler');
            $permission = Permission::firstOrCreate(['slug' => 'payment_links.refresh'], ['name' => 'Refresh checkout', 'resource' => 'payment_links', 'action' => 'refresh']);
            $lookupActor->roles()->firstOrFail()->permissions()->attach($permission);
            $provider->status = 'completed';
            $workflow->refresh($link, [], $lookupActor);
            $workflow->refresh($link, [], $lookupActor);
            $this->assertDatabaseCount('clinic_notifications', 2);
            $this->assertDatabaseCount('payments', 1);
            $receipt = ClinicNotification::where('event', 'payment.recorded')->firstOrFail();
            $this->assertSame('payment_provider', $receipt->trigger);
            $this->assertNull($receipt->actor_id);
            $lookupActor->tenants()->detach(app(TenantContext::class)->requireId());
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages); // Completed checkout notice is suppressed.
            $this->assertStringContainsString('USD 100.00', $this->provider->messages[0]['message']->body);
        });
    }

    public function test_authoritative_system_partial_settlement_records_amount_balance_and_queues_once(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $invoice = Invoice::factory()->issued()->create();
            $this->contact($invoice->patient, $actor, 'patient@example.test');
            $link = $this->link($invoice);
            $link->update(['external_reference' => 'checkout-system', 'amount' => '25.00']);
            $result = new \App\Billing\Providers\CheckoutResult('checkout-system', null, 'completed', null, 2500, 'USD', 'transaction-system');
            DB::transaction(function () use ($invoice, $link, $result) {
                $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
                $link = PaymentLink::lockForUpdate()->findOrFail($link->id);
                app(\App\Services\PaymentSettlement::class)->record($invoice, $link, $result);
                app(\App\Services\PaymentSettlement::class)->record($invoice, $link, $result);
                $link->update(['status' => 'completed']);
            });
            $notification = ClinicNotification::firstOrFail();
            $this->assertSame('payment_provider', $notification->trigger);
            $this->assertFalse($notification->user_triggered);
            $this->assertNull($notification->actor_id);
            $this->assertSame($link->id, $notification->payment_link_id);
            $this->assertSame('USD 25.00', $notification->variables['amount']);
            $this->assertSame('USD 75.00', $notification->variables['balance']);
            $this->assertDatabaseCount('clinic_notifications', 1);
            $this->assertDatabaseCount('notification_deliveries', 1);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            $this->assertStringContainsString('USD 25.00', $this->provider->messages[0]['message']->body);
            $this->assertStringContainsString('USD 75.00', $this->provider->messages[0]['message']->body);
            $this->assertDatabaseHas('audit_logs', ['action' => 'queued', 'resource_type' => 'clinic_notifications', 'actor_id' => null]);
        });
    }

    public function test_authoritative_failed_and_expired_refresh_queue_one_terminal_notice_and_suppress_invitations(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $settings = $this->settings();
            foreach (['failed', 'expired'] as $status) {
                $settings['events']['payment_link.'.$status] = ['channels' => ['email']];
            }
            app(CommunicationConfigurationService::class)->configure($settings, true);
            $provider = new \Tests\Fakes\FakePaymentProvider;
            $this->app->instance(\Tests\Fakes\FakePaymentProvider::class, $provider);
            config(['billing.providers.handypay' => \Tests\Fakes\FakePaymentProvider::class]);
            PaymentProviderConfiguration::create(['provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_secret'],
                'success_url' => 'https://clinic.test/ok', 'cancel_url' => 'https://clinic.test/cancel', 'enabled' => true]);
            $workflow = app(\App\Services\PaymentLinkWorkflow::class);
            foreach (['failed', 'expired'] as $index => $status) {
                $provider->status = 'active';
                $invoice = Invoice::factory()->issued()->create();
                $this->contact($invoice->patient, $actor, 'patient@example.test', events: array_keys(SafeTemplate::DEFAULTS));
                $link = $workflow->create($invoice, ['idempotency_key' => 'terminal-notice'], $actor);
                $provider->status = $status;
                $workflow->refresh($link, [], $actor);
                $workflow->refresh($link, [], $actor);
                $notice = ClinicNotification::where('event', 'payment_link.'.$status)->sole();
                $this->assertSame($link->id, $notice->payment_link_id);
                $this->assertNull($notice->payment_id);
                $this->artisan('communications:work')->assertSuccessful();
                $this->assertCount($index + 1, $this->provider->messages);
                $this->assertStringContainsString($status, $this->provider->messages[$index]['message']->body);
                $this->assertStringNotContainsString($link->checkout_url, $this->provider->messages[$index]['message']->body);
            }
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(0, ClinicNotification::where('event', 'payment.recorded')->count());
        });
    }

    public function test_ambiguous_creation_failures_do_not_claim_failure_but_definite_failures_can_notify(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $settings = $this->settings();
            $settings['events']['payment_link.failed'] = ['channels' => ['email']];
            app(CommunicationConfigurationService::class)->configure($settings, true);
            $provider = new \Tests\Fakes\FakePaymentProvider;
            $this->app->instance(\Tests\Fakes\FakePaymentProvider::class, $provider);
            config(['billing.providers.handypay' => \Tests\Fakes\FakePaymentProvider::class]);
            PaymentProviderConfiguration::create(['provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_secret'],
                'success_url' => 'https://clinic.test/ok', 'cancel_url' => 'https://clinic.test/cancel', 'enabled' => true]);
            foreach ([true, false] as $uncertain) {
                $invoice = Invoice::factory()->issued()->create();
                $this->contact($invoice->patient, $actor, 'patient@example.test', events: ['payment_link.failed']);
                $provider->failure = new \App\Billing\Providers\ProviderException('unavailable', uncertain: $uncertain);
                try {
                    app(\App\Services\PaymentLinkWorkflow::class)->create($invoice, ['idempotency_key' => 'creation-failure'], $actor);
                    $this->fail('Provider creation failure must be reported.');
                } catch (\App\Billing\Providers\ProviderException) {
                    $this->assertSame($uncertain ? 'unknown' : 'failed', $invoice->paymentLinks()->sole()->status);
                }
                $this->assertSame($uncertain ? 0 : 1, ClinicNotification::count());
            }
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
        });
    }

    public function test_unsent_terminal_notices_are_suppressed_after_a_replacement_link_or_settlement(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $settings = $this->settings();
            $settings['events']['payment_link.expired'] = ['channels' => ['email']];
            app(CommunicationConfigurationService::class)->configure($settings, true);
            foreach (['replacement', 'settlement'] as $scenario) {
                $invoice = Invoice::factory()->issued()->create();
                $this->contact($invoice->patient, $actor, 'patient@example.test', events: ['payment_link.expired']);
                $link = $this->link($invoice);
                $link->update(['status' => 'expired']);
                $notice = app(ClinicNotificationService::class)->paymentLinkUnavailable($link->id);
                if ($scenario === 'replacement') {
                    $replacement = $link->replicate();
                    $replacement->forceFill(['status' => 'active', 'operation_key' => (string) Str::uuid(), 'idempotency_key' => 'replacement'])->save();
                } else {
                    app(BillingWorkflow::class)->recordPayment(['invoice_id' => $invoice->id, 'amount' => '100.00',
                        'currency' => 'USD', 'payment_method' => 'cash', 'idempotency_key' => 'settled-after-expiry'], $actor);
                }
                $this->artisan('communications:work')->assertSuccessful();
                $this->assertSame('payment_link_outcome_superseded', $notice->deliveries()->sole()->failure_reason);
            }
            $this->assertCount(0, $this->provider->messages);
        });
    }

    public function test_sms_final_delivery_and_failure_status_are_recorded_without_resending(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $payment = Payment::factory()->create();
            $this->contact($payment->invoice->patient, $actor, '+15555550101', channel: 'sms');
            $this->contact($payment->invoice->patient, $actor, '+15555550102', channel: 'sms');
            app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->app->forgetInstance(TwilioProvider::class);
            \Illuminate\Support\Facades\Http::preventStrayRequests();
            $first = 'SM'.str_repeat('a', 32);
            $second = 'SM'.str_repeat('b', 32);
            \Illuminate\Support\Facades\Http::fake([
                '*/Messages.json' => \Illuminate\Support\Facades\Http::sequence()->push(['sid' => $first], 201)->push(['sid' => $second], 201),
                '*/Messages/'.$first.'.json' => \Illuminate\Support\Facades\Http::response(['sid' => $first, 'status' => 'delivered']),
                '*/Messages/'.$second.'.json' => \Illuminate\Support\Facades\Http::response(['sid' => $second, 'status' => 'undelivered', 'error_message' => 'private detail']),
            ]);
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame(2, NotificationDelivery::where('status', 'accepted')->count());
            $this->travel(6)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertDatabaseHas('notification_deliveries', ['provider_reference' => $first, 'status' => 'delivered', 'attempts' => 1]);
            $this->assertDatabaseHas('notification_deliveries', ['provider_reference' => $second, 'status' => 'failed', 'failure_reason' => 'provider_delivery_failed', 'attempts' => 1]);
            \Illuminate\Support\Facades\Http::assertSentCount(4);
            $this->assertStringNotContainsString('private detail', AuditLog::all()->toJson());
        });
    }

    public function test_deleted_actor_cannot_become_a_system_trigger_and_contact_addresses_are_immutable(): void
    {
        $this->within(function () {
            [$actor, $payment, $delivery] = $this->queued();
            $contact = PatientContact::firstOrFail();
            try {
                $contact->update(['address' => 'other@example.test']);
                $this->fail('Verified contact addresses must not change in place.');
            } catch (ValidationException) {
                $this->assertSame('patient@example.test', $contact->refresh()->address);
            }
            $actor->delete();
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertSame('trigger_no_longer_authorized', $delivery->refresh()->failure_reason);
            $this->assertCount(0, $this->provider->messages);
        });
    }

    public function test_disable_succeeds_after_copy_staff_leave_and_requires_explicit_context_to_queue(): void
    {
        $payment = $this->within(function () {
            $actor = $this->actor();
            $this->configure([$actor->id]);
            $payment = Payment::factory()->create();

            return $payment;
        });
        try {
            app(ClinicNotificationService::class)->paymentRecorded($payment->id, null);
            $this->fail('Enabled notifications must capture an explicit verified clinic context.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('clinic_notifications', 0);
        }
        $this->within(function () {
            User::firstOrFail()->tenants()->detach(app(TenantContext::class)->requireId());
            $this->artisan('communications:configure', ['tenant' => app(TenantContext::class)->requireId(), '--disable' => true])->assertSuccessful();
            $this->assertFalse(CommunicationConfiguration::firstOrFail()->enabled);
        });
    }

    public function test_changing_internal_copy_email_requires_reverification_and_suppresses_the_original_delivery(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $copy = $this->actor(Role::BILLING);
            $this->configure([$copy->id]);
            $payment = Payment::factory()->create();
            $this->contact($payment->invoice->patient, $actor, 'patient@example.test');
            app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $copy->update(['email' => 'unverified-replacement@example.test']);
            $this->assertNull($copy->refresh()->email_verified_at);
            $this->assertFalse(app(CommunicationConfigurationService::class)->eligibleCopyUser($copy));
            $this->artisan('communications:work')->assertSuccessful();
            $this->assertCount(1, $this->provider->messages);
            $this->assertSame('patient@example.test', $this->provider->messages[0]['message']->to);
            $this->assertDatabaseHas('notification_deliveries', ['copy_user_id' => $copy->id, 'status' => 'suppressed', 'failure_reason' => 'recipient_no_longer_authorized']);
        });
    }

    public function test_changed_sms_credentials_end_status_polling_without_resending_or_starving_new_messages(): void
    {
        $this->within(function () {
            $actor = $this->actor();
            $this->configure();
            $payment = Payment::factory()->create();
            $this->contact($payment->invoice->patient, $actor, '+15555550101', channel: 'sms');
            $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);
            $this->artisan('communications:work')->assertSuccessful();
            $this->configure();
            $this->travel(6)->minutes();
            $this->artisan('communications:work')->assertSuccessful();
            $this->artisan('communications:work')->assertSuccessful();
            $delivery = $notification->deliveries()->firstOrFail();
            $this->assertSame('unknown', $delivery->status);
            $this->assertSame('configuration_changed', $delivery->failure_reason);
            $this->assertNotNull($delivery->accepted_at);
            $this->assertSame(1, $delivery->attempts);
            $this->assertCount(1, $this->provider->messages);
        });
    }

    private function within(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantContext::class)->run($tenant ?? Tenant::where('slug', Tenant::DEFAULT_SLUG)->firstOrFail(), $callback(...));
    }

    private function actor(string $slug = Role::ADMIN): User
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $actor->roles()->attach($role);

        return $actor;
    }

    private function settings(array $copies = [], string $sender = 'clinic@example.test'): array
    {
        return ['channels' => [
            'email' => ['provider' => 'smtp', 'sender' => $sender, 'host' => 'smtp.example.test', 'port' => 465, 'username' => 'clinic', 'password' => 'password-secret'],
            'sms' => ['provider' => 'twilio', 'sender' => '+15555550100', 'account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'token-secret'],
        ], 'events' => [
            'payment.recorded' => ['channels' => ['email', 'sms'], 'copy_user_ids' => $copies],
            'payment_link.created' => ['channels' => ['email', 'sms'], 'copy_user_ids' => $copies],
        ]];
    }

    private function configure(array $copies = [], string $sender = 'clinic@example.test'): void
    {
        app(CommunicationConfigurationService::class)->configure($this->settings($copies, $sender), true);
    }

    private function contact(Patient $patient, User $actor, string $address, string $channel = 'email', string $role = 'patient', bool $verified = true, array $events = ['payment.recorded', 'payment_link.created']): PatientContact
    {
        $service = app(PatientContactService::class);
        $contact = $service->create($patient, ['channel' => $channel, 'address' => $address, 'role' => $role, 'authorized_events' => $events], $actor);

        return $verified ? $service->verify($patient, $contact->id, 'in_person', $actor) : $contact;
    }

    private function queued(): array
    {
        $actor = $this->actor();
        $this->configure();
        $payment = Payment::factory()->create();
        $this->contact($payment->invoice->patient, $actor, 'patient@example.test');
        $notification = app(ClinicNotificationService::class)->paymentRecorded($payment->id, $actor->id);

        return [$actor, $payment, $notification->deliveries()->firstOrFail()];
    }

    private function link(Invoice $invoice): PaymentLink
    {
        $configuration = PaymentProviderConfiguration::create(['provider' => 'fake', 'credentials' => ['api_key' => 'secret'], 'success_url' => 'https://clinic.test/ok', 'cancel_url' => 'https://clinic.test/cancel', 'enabled' => true]);

        return $invoice->paymentLinks()->create(['payment_provider_configuration_id' => $configuration->id, 'provider' => 'fake', 'operation_key' => (string) Str::uuid(),
            'idempotency_key' => 'checkout', 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'status' => 'active', 'checkout_url' => 'https://pay.example.test/secret-checkout', 'expires_at' => now()->addHour()]);
    }
}
