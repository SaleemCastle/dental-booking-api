<?php

namespace Tests\Feature;

use App\Billing\Money;
use App\Billing\Providers\CheckoutRequest;
use App\Billing\Providers\HandyPayProvider;
use App\Models\Invoice;
use App\Models\PaymentProviderConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Opt-in tests against a real HandyPay TEST merchant. No live key is accepted. */
class HandyPaySandboxTest extends TestCase
{
    use RefreshDatabase;

    private function sandboxKey(): string
    {
        if (getenv('HANDYPAY_SANDBOX_RUN') !== '1') {
            $this->markTestSkipped('Real merchant sandbox tests require HANDYPAY_SANDBOX_RUN=1 and test-only credentials.');
        }
        $key = getenv('HANDYPAY_SANDBOX_API_KEY');
        if (! is_string($key) || ! preg_match('/\Ahp_test_[A-Za-z0-9_-]+\z/', $key)) {
            $this->fail('A test-mode merchant key is required; live credentials are refused.');
        }

        return $key;
    }

    public function test_real_sandbox_creates_and_retrieves_a_hosted_checkout(): void
    {
        $key = $this->sandboxKey();
        $success = getenv('HANDYPAY_SANDBOX_SUCCESS_URL');
        $cancel = getenv('HANDYPAY_SANDBOX_CANCEL_URL');
        foreach ([$success, $cancel] as $url) {
            $this->assertTrue(is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https', 'Configure HTTPS sandbox return URLs.');
        }
        $provider = new HandyPayProvider;
        $session = $provider->createCheckout(new CheckoutRequest((string) Str::uuid(), 1000, 'USD', $success, $cancel), ['api_key' => $key]);
        $this->assertSame('active', $session->status);
        $this->assertNotNull($session->checkoutUrl);
        $lookup = $provider->checkoutStatus($session->reference, ['api_key' => $key]);
        $this->assertSame($session->reference, $lookup->reference);
        $this->assertSame(1000, $lookup->amountMinor);
        $this->assertSame('USD', $lookup->currency);
        // This creates an unpaid test session only. HandyPay has no documented session cancellation endpoint.
    }

    public function test_real_paid_sandbox_session_and_captured_signed_delivery_settle_once_end_to_end(): void
    {
        $key = $this->sandboxKey();
        $path = getenv('HANDYPAY_SANDBOX_WEBHOOK_FILE');
        $signature = getenv('HANDYPAY_SANDBOX_WEBHOOK_SIGNATURE');
        $secret = getenv('HANDYPAY_SANDBOX_WEBHOOK_SECRET');
        if (! is_string($path) || ! is_file($path) || ! is_string($signature) || ! is_string($secret) || $secret === '') {
            $this->markTestSkipped('Supply a private captured sandbox webhook file, its original signature, and endpoint signing secret.');
        }
        $amount = getenv('HANDYPAY_SANDBOX_EXPECTED_AMOUNT_MINOR');
        $currency = getenv('HANDYPAY_SANDBOX_EXPECTED_CURRENCY');
        $this->assertTrue(is_string($amount) && ctype_digit($amount) && (int) $amount > 0 && (int) $amount <= 99999999, 'Supply the independently expected sandbox amount in minor units.');
        $this->assertContains($currency, ['USD', 'JMD']);
        $body = file_get_contents($path);
        $credentials = ['api_key' => $key, 'webhook_secret' => $secret];
        $provider = new HandyPayProvider;
        $event = $provider->verifyWebhook($body, $signature, $credentials);
        $this->assertSame('completed', $event->status);
        $this->assertNotNull($event->sessionReference, 'Capture a successful checkout session delivery.');
        $this->assertSame((int) $amount, $event->amountMinor);
        $this->assertSame($currency, $event->currency);
        $this->assertNotSame(true, $event->liveMode);
        // Independently confirm this session belongs to the supplied TEST merchant and is actually paid.
        $lookup = $provider->checkoutStatus($event->sessionReference, $credentials);
        $this->assertSame('completed', $lookup->status);
        $this->assertSame((int) $amount, $lookup->amountMinor);
        $this->assertSame($currency, $lookup->currency);
        $configuration = PaymentProviderConfiguration::create([
            'provider' => 'handypay', 'credentials' => $credentials, 'enabled' => true,
            'success_url' => 'https://example.test/success', 'cancel_url' => 'https://example.test/cancel',
        ]);
        $invoice = Invoice::factory()->issued()->create(['amount_due' => Money::decimal((int) $amount), 'currency' => $currency]);
        $invoice->paymentLinks()->create([
            'payment_provider_configuration_id' => $configuration->id, 'provider' => 'handypay',
            'external_reference' => $event->sessionReference, 'operation_key' => $event->operationKey ?? (string) Str::uuid(),
            'idempotency_key' => 'real-sandbox', 'status' => 'active', 'amount' => $invoice->amount_due, 'currency' => $currency,
        ]);
        for ($delivery = 0; $delivery < 2; $delivery++) {
            $this->call('POST', '/api/webhooks/handypay/'.$configuration->webhook_endpoint, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HANDYPAY_SIGNATURE' => $signature,
            ], $body)->assertOk();
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_provider_events', 1);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame('0.00', $invoice->balance);
    }
}
