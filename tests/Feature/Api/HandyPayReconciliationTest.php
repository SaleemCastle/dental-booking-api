<?php

namespace Tests\Feature\Api;

use App\Billing\Providers\HandyPayProvider;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BillingWorkflow;
use App\Services\PaymentWebhookWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Sandbox contract E2E: real adapter, API, HMAC, persistence and invoice ledger; HTTP boundary is stubbed. */
class HandyPayReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sandbox_endpoint_signing_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['billing.providers.handypay' => HandyPayProvider::class]);
    }

    public function test_sandbox_checkout_webhook_and_duplicate_deliveries_reconcile_the_remaining_balance(): void
    {
        $actor = $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create(['amount_due' => '100.37', 'currency' => 'JMD']);
        app(BillingWorkflow::class)->recordPayment([
            'invoice_id' => $invoice->id, 'amount' => '25.00', 'currency' => 'JMD',
            'payment_method' => 'cash', 'idempotency_key' => 'prior-cash',
        ], $actor);
        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertSame('75.37', $invoice->balance);
        $link = $this->checkout($invoice);
        $this->assertSame('75.37', $link->amount);
        $this->assertSame('25.00', $invoice->refresh()->amount_paid);
        $this->assertNull($configuration->refresh()->sandbox_verified_at);
        $event = $this->event($link);
        $this->deliver($configuration, $event)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame('100.37', $invoice->amount_paid);
        $this->assertSame('0.00', $invoice->balance);
        $this->assertSame('completed', $link->refresh()->status);
        $this->assertNotNull($configuration->refresh()->sandbox_verified_at);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'payment_link_id' => $link->id,
            'amount' => '75.37', 'currency' => 'JMD', 'reference' => 'pi_sandbox_payment',
            'provider_transaction_reference' => 'pi_sandbox_payment',
        ]);
        $audits = AuditLog::count();
        $this->deliver($configuration, $event)->assertOk();
        $this->deliver($configuration, [...$event, 'id' => 'evt_second_success', 'type' => 'checkout.session.async_payment_succeeded'])->assertOk();
        $this->assertDatabaseCount('payments', 2); // Prior cash plus exactly one provider payment.
        $this->assertDatabaseCount('payment_provider_events', 2);
        $this->assertSame($audits, AuditLog::count());
        Http::assertSentCount(1); // Callbacks do not wait on external HTTP under a database lock.
    }

    public static function invalidSignatures(): array
    {
        return [[''], ['sha256='.str_repeat('0', 64)], ['sha256=short'], ['v1='.str_repeat('a', 64)]];
    }

    #[DataProvider('invalidSignatures')]
    public function test_unsigned_or_forged_callbacks_are_rejected_before_any_writes(string $signature): void
    {
        $configuration = $this->configuration();
        $this->raw($configuration, '{"id":"evt_attack"}', $signature)->assertUnauthorized()
            ->assertJsonPath('code', 'PAYMENT_WEBHOOK_INVALID_SIGNATURE');
        $this->assertDatabaseCount('payment_provider_events', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_signatures_cover_exact_raw_bytes_and_malformed_signed_json_is_safe(): void
    {
        $configuration = $this->configuration();
        $body = '{"id":"evt_test", "type":"unknown.event", "data":{}}';
        $signature = 'sha256='.hash_hmac('sha256', $body, self::SECRET);
        $this->raw($configuration, $body.' ', $signature)->assertUnauthorized();
        $this->raw($configuration, $body, $signature)->assertOk();
        $body = '{invalid';
        $this->raw($configuration, $body, 'sha256='.hash_hmac('sha256', $body, self::SECRET))->assertStatus(400);
        $this->assertDatabaseCount('payment_provider_events', 1);
    }

    public static function unsettledEvents(): array
    {
        return [
            ['checkout.session.completed', ['payment_status' => 'unpaid'], 'pending'],
            ['checkout.session.expired', [], 'expired'],
            ['checkout.session.async_payment_failed', [], 'failed'],
            ['payment_intent.payment_failed', ['id' => 'pi_sandbox_payment', 'status' => 'requires_payment_method', 'amount_received' => 0], 'pending'],
            ['payment_intent.payment_failed', ['id' => 'pi_sandbox_payment', 'status' => 'canceled', 'amount_received' => 0], 'cancelled'],
        ];
    }

    #[DataProvider('unsettledEvents')]
    public function test_unpaid_failed_expired_and_cancelled_events_never_create_payments(string $type, array $data, string $status): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $event = $this->event($link);
        $event['type'] = $type;
        $event['data'] = array_replace($event['data'], $data);
        $this->deliver($configuration, $event)->assertOk();
        $this->assertSame($status, $link->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('100.00', $invoice->balance);
        $this->assertNull($configuration->refresh()->sandbox_verified_at);
        $this->assertDatabaseCount('payments', 0);
        if ($status === 'pending') {
            $this->postJson('/api/invoices/'.$invoice->id.'/payment-links', ['idempotency_key' => 'unsafe-replacement'])->assertUnprocessable();
        }
    }

    public static function mismatches(): array
    {
        return [
            [['amount_total' => 1]], [['amount_total' => 10001]], [['currency' => 'jmd']],
            [['metadata' => ['operation_key' => '00000000-0000-4000-8000-000000000000']]],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_signed_mismatches_require_reconciliation_without_settlement(array $extra): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $event = $this->event($link);
        $event['data'] = array_replace($event['data'], $extra);
        $this->deliver($configuration, $event)->assertOk();
        $this->assertDatabaseHas('payment_provider_events', ['outcome' => 'reconciliation_required']);
        $this->assertSame('active', $link->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_success_requires_amount_currency_and_explicit_paid_status(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $link = $this->checkout(Invoice::factory()->issued()->create());
        foreach (['amount_total', 'currency', 'payment_status'] as $missing) {
            $event = $this->event($link);
            $event['type'] = 'checkout.session.async_payment_succeeded';
            unset($event['data'][$missing]);
            $this->deliver($configuration, $event)->assertStatus(400);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_provider_events', 0);
    }

    public function test_callback_uses_endpoint_tenant_and_ignores_browser_authentication_and_tenant_headers(): void
    {
        $this->actor();
        $local = $this->configuration();
        $foreignTenant = Tenant::factory()->create();
        [$foreign, $invoice, $link] = app(TenantContext::class)->run($foreignTenant, function () {
            $configuration = $this->configuration('hp_test_foreign', 'foreign_endpoint_signing_secret');
            $invoice = Invoice::factory()->issued()->create();
            $link = $invoice->paymentLinks()->create([
                'provider' => 'handypay', 'payment_provider_configuration_id' => $configuration->id,
                'operation_key' => fake()->uuid(), 'idempotency_key' => 'foreign', 'amount' => '100.00',
                'currency' => 'USD', 'status' => 'active', 'external_reference' => 'cs_test_foreign',
            ]);

            return [$configuration, $invoice, $link];
        });
        $event = $this->event($link);
        $this->deliver($local, $event)->assertOk();
        $this->assertDatabaseHas('payment_provider_events', ['outcome' => 'unmatched']);
        $this->assertDatabaseCount('payments', 0);
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $this->withHeader('X-Tenant-Id', '999999')->raw($foreign, $body, 'sha256='.hash_hmac('sha256', $body, 'foreign_endpoint_signing_secret'))->assertOk();
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'tenant_id' => $foreignTenant->id]);
        $this->assertSame(0, Payment::count());
    }

    public function test_pending_async_success_and_late_failure_do_not_double_record_or_regress_status(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $event = $this->event($link);
        $event['data']['payment_status'] = 'unpaid';
        $this->deliver($configuration, $event)->assertOk();
        $this->assertSame('pending', $link->refresh()->status);
        $event = $this->event($link);
        $event['id'] = 'evt_async_paid';
        $event['type'] = 'checkout.session.async_payment_succeeded';
        $this->deliver($configuration, $event)->assertOk();
        $event['id'] = 'evt_late_failure';
        $event['type'] = 'checkout.session.async_payment_failed';
        $this->deliver($configuration, $event)->assertOk();
        $this->assertSame('completed', $link->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_intent_success_reconciles_via_opaque_operation_key(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $link = $this->checkout(Invoice::factory()->issued()->create());
        $event = $this->event($link);
        $event['type'] = 'payment_intent.succeeded';
        $event['data'] = ['id' => 'pi_sandbox_payment', 'status' => 'succeeded', 'amount_received' => 10000,
            'currency' => 'usd', 'metadata' => ['operation_key' => $link->operation_key]];
        $this->deliver($configuration, $event)->assertOk();
        $this->deliver($configuration, $this->event($link, 'evt_session_after_intent'))->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('completed', $link->refresh()->status);
    }

    public function test_reusing_event_id_with_changed_content_is_rejected(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $link = $this->checkout(Invoice::factory()->issued()->create());
        $event = $this->event($link);
        $this->deliver($configuration, $event)->assertOk();
        $event['data']['payment_intent'] = 'pi_different';
        $this->deliver($configuration, $event)->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unrelated_merchant_payment_is_acknowledged_and_retained_for_reconciliation(): void
    {
        $configuration = $this->configuration();
        $this->deliver($configuration, ['id' => 'evt_unrelated', 'type' => 'payment_intent.succeeded',
            'data' => ['id' => 'pi_unrelated', 'status' => 'succeeded', 'amount_received' => 10000, 'currency' => 'usd'],
        ])->assertOk();
        $this->assertDatabaseHas('payment_provider_events', ['event_id' => 'evt_unrelated', 'outcome' => 'unmatched']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_disabled_credential_revision_still_verifies_delayed_deliveries(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $configuration->update(['enabled' => false]);
        $this->deliver($configuration, $this->event($link))->assertOk();
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_provider_transaction_cannot_be_settled_again_on_another_invoice(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $first = $this->checkout(Invoice::factory()->issued()->create());
        $this->deliver($configuration, $this->event($first))->assertOk();
        $other = Invoice::factory()->issued()->create();
        $second = $other->paymentLinks()->create([
            'payment_provider_configuration_id' => $configuration->id, 'provider' => 'handypay',
            'operation_key' => fake()->uuid(), 'idempotency_key' => 'another-invoice', 'amount' => '100.00', 'currency' => 'USD',
            'status' => 'active', 'external_reference' => 'cs_test_second',
        ]);
        $this->deliver($configuration, $this->event($second, 'evt_other_invoice'))->assertOk();
        $this->assertDatabaseHas('payment_provider_events', ['event_id' => 'evt_other_invoice', 'outcome' => 'reconciliation_required']);
        $this->assertSame('issued', $other->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_audit_failure_rolls_back_event_and_ledger_and_provider_retry_succeeds_without_logging_secrets(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $realAudit = app(AuditLogService::class);
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('upstream hp_test_sandbox '.self::SECRET));
        $this->app->instance(AuditLogService::class, $audit);
        $this->app->forgetInstance(PaymentWebhookWorkflow::class);
        Log::spy();
        $event = $this->event($link);
        $response = $this->deliver($configuration, $event)->assertStatus(503);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        Log::shouldNotHaveReceived('error');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_provider_events', 0);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->app->instance(AuditLogService::class, $realAudit);
        $this->deliver($configuration, $event)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_callback_before_creation_response_cannot_be_overwritten_by_open_status(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        Http::fake(function ($request) use ($configuration) {
            $link = PaymentLink::firstOrFail();
            $event = $this->event($link);
            $event['data']['id'] = 'cs_test_checkout';
            $body = json_encode($event, JSON_THROW_ON_ERROR);
            app(PaymentWebhookWorkflow::class)->receive($configuration->webhook_endpoint, $body, 'sha256='.hash_hmac('sha256', $body, self::SECRET));

            return Http::response($this->sandboxSession($request['line_items'][0]['amount'], $request['line_items'][0]['currency']));
        });
        $this->postJson('/api/invoices/'.$invoice->id.'/payment-links', ['idempotency_key' => 'early'])
            ->assertCreated()->assertJsonPath('data.payment_link.status', 'completed');
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_refresh_is_authoritative_and_webhook_after_refresh_is_idempotent(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        Http::fake(['api.handypay.me/*' => Http::response($this->sandboxSession(10000, 'usd', [
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_sandbox_payment', 'url' => null,
        ]))]);
        $this->postJson('/api/payment-links/'.$link->id.'/refresh')->assertOk()->assertJsonPath('data.payment_link.status', 'completed');
        $this->deliver($configuration, $this->event($link))->assertOk();
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_browser_success_and_cancellation_parameters_cannot_record_payment(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $link = $this->checkout($invoice);
        $this->getJson('/api/payment-links/'.$link->id.'?session_id='.$link->external_reference.'&status=paid&success=true')->assertOk();
        $this->getJson('/api/webhooks/handypay/'.$link->configuration->webhook_endpoint.'?success=true')->assertStatus(405);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_live_mode_is_blocked_until_sandbox_has_settled_and_test_live_mismatch_cannot_settle(): void
    {
        $this->actor();
        $configuration = $this->configuration('hp_live_sandbox');
        $invoice = Invoice::factory()->issued()->create();
        $this->postJson('/api/invoices/'.$invoice->id.'/payment-links', ['idempotency_key' => 'live'])->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_SANDBOX_VERIFICATION_REQUIRED');
        Http::assertNothingSent();
        $configuration->update(['credentials' => ['api_key' => 'hp_test_sandbox', 'webhook_secret' => self::SECRET]]);
        $link = $this->checkout($invoice);
        $event = $this->event($link);
        $event['livemode'] = true;
        $this->deliver($configuration, $event)->assertOk();
        $this->assertDatabaseHas('payment_provider_events', ['outcome' => 'reconciliation_required']);
        $this->assertDatabaseCount('payments', 0);
    }

    private function actor(): User
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => Role::BILLING], ['name' => 'Billing']);
        $actor->roles()->attach($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function configuration(string $key = 'hp_test_sandbox', string $secret = self::SECRET): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::create([
            'provider' => 'handypay', 'credentials' => ['api_key' => $key, 'webhook_secret' => $secret],
            'success_url' => 'https://clinic.example.test/success', 'cancel_url' => 'https://clinic.example.test/cancel', 'enabled' => true,
        ]);
    }

    private function checkout(Invoice $invoice): PaymentLink
    {
        $payload = $this->sandboxSession(\App\Billing\Money::minor($invoice->refresh()->balance), strtolower($invoice->currency));
        Http::fake(fn ($request) => $request->method() === 'POST' ? Http::response($payload) : null);
        $response = $this->postJson('/api/invoices/'.$invoice->id.'/payment-links', ['idempotency_key' => 'sandbox-checkout'])->assertCreated();

        return PaymentLink::findOrFail($response->json('data.payment_link.id'));
    }

    private function sandboxSession(int $amount, string $currency, array $extra = []): array
    {
        return ['success' => true, 'data' => [
            'id' => 'cs_test_checkout', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout',
            'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => $amount, 'currency' => $currency, ...$extra,
        ]];
    }

    private function event(PaymentLink $link, string $id = 'evt_sandbox_success'): array
    {
        return ['id' => $id, 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false,
            'data' => ['id' => $link->external_reference, 'status' => 'complete', 'payment_status' => 'paid',
                'amount_total' => \App\Billing\Money::minor($link->amount), 'currency' => strtolower($link->currency),
                'payment_intent' => 'pi_sandbox_payment', 'metadata' => ['operation_key' => $link->operation_key]]];
    }

    private function deliver(PaymentProviderConfiguration $configuration, array $event)
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->raw($configuration, $body, 'sha256='.hash_hmac('sha256', $body, self::SECRET));
    }

    private function raw(PaymentProviderConfiguration $configuration, string $body, string $signature)
    {
        return $this->call('POST', '/api/webhooks/handypay/'.$configuration->webhook_endpoint, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HANDYPAY_SIGNATURE' => $signature,
        ], $body);
    }
}
