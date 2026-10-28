<?php

namespace Tests\Feature;

use App\Billing\Providers\CheckoutRequest;
use App\Billing\Providers\HandyPayProvider;
use App\Billing\Providers\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HandyPayProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_hosted_checkout_uses_documented_envelope_minor_units_and_no_patient_or_card_payload(): void
    {
        Http::fake(['api.handypay.me/*' => Http::response($this->sessionPayload(), 200)]);
        $result = (new HandyPayProvider)->createCheckout($this->checkout(), ['api_key' => 'hp_test_one']);
        $this->assertSame('cs_test_checkout', $result->reference);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_checkout', $result->checkoutUrl);
        $this->assertSame('active', $result->status);
        $this->assertNull($result->expiresAt); // The published session schema does not supply expiry.
        $this->assertSame(1234, $result->amountMinor);
        $this->assertSame('JMD', $result->currency);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.handypay.me/api/v1/payment-sessions'
                && $request->hasHeader('Authorization', 'Bearer hp_test_one')
                && $request->data() === [
                    'line_items' => [['amount' => 1234, 'currency' => 'jmd', 'name' => 'Clinic invoice', 'quantity' => 1]],
                    'success_url' => 'https://clinic.example.test/success', 'cancel_url' => 'https://clinic.example.test/cancel',
                    'pass_fees_to_customer' => false, 'metadata' => ['operation_key' => 'opaque-attempt'],
                ];
        });
        Http::assertSentCount(1);
    }

    public function test_lookup_maps_completed_pending_and_expired_without_guessing_settlement(): void
    {
        Http::fakeSequence()
            ->push($this->sessionPayload(['status' => 'complete', 'payment_status' => 'unpaid', 'url' => null]))
            ->push($this->sessionPayload(['status' => 'complete', 'payment_status' => 'paid', 'url' => null]))
            ->push($this->sessionPayload(['status' => 'expired', 'url' => null]));
        $provider = new HandyPayProvider;
        foreach (['pending', 'completed', 'expired'] as $status) {
            $this->assertSame($status, $provider->checkoutStatus('cs_test_checkout', ['api_key' => 'hp_test_one'])->status);
        }
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.handypay.me/api/v1/payment-sessions/cs_test_checkout');
    }

    public static function errors(): array
    {
        return [[400, 'rejected', false], [401, 'authentication', false], [403, 'authentication', false],
            [429, 'rate_limited', false], [409, 'reconciliation_required', true],
            [500, 'unavailable', true], [502, 'unavailable', true], [302, 'unavailable', true]];
    }

    #[DataProvider('errors')]
    public function test_upstream_errors_are_sanitized_and_never_automatically_retried(int $httpStatus, string $reason, bool $uncertain): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['message' => 'secret hp_test_one']], $httpStatus)]);
        try {
            (new HandyPayProvider)->createCheckout($this->checkout(), ['api_key' => 'hp_test_one']);
            $this->fail('Expected provider failure.');
        } catch (ProviderException $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertSame($uncertain, $error->uncertain);
            $this->assertStringNotContainsString('secret', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public function test_network_timeout_is_uncertain_and_does_not_retain_the_transport_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Bearer hp_test_private'));
        try {
            (new HandyPayProvider)->createCheckout($this->checkout(), ['api_key' => 'hp_test_one']);
            $this->fail('Expected timeout.');
        } catch (ProviderException $error) {
            $this->assertSame('timeout', $error->reason);
            $this->assertTrue($error->uncertain);
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_cancellation_is_explicitly_unsupported_and_sends_no_request(): void
    {
        Http::fake();
        try {
            (new HandyPayProvider)->cancelCheckout('cs_test_checkout', ['api_key' => 'hp_test_one']);
            $this->fail('Expected unsupported operation.');
        } catch (ProviderException $error) {
            $this->assertSame('unsupported', $error->reason);
        }
        Http::assertNothingSent();
    }

    public function test_currency_exponents_amount_limits_and_missing_keys_fail_before_network(): void
    {
        Http::fake();
        foreach ([
            [new CheckoutRequest('key', 1234, 'JPY', 'https://clinic.test/success', 'https://clinic.test/cancel'), ['api_key' => 'hp_test_one']],
            [new CheckoutRequest('key', 100000000, 'JMD', 'https://clinic.test/success', 'https://clinic.test/cancel'), ['api_key' => 'hp_test_one']],
            [$this->checkout(), []],
        ] as [$request, $credentials]) {
            try {
                (new HandyPayProvider)->createCheckout($request, $credentials);
                $this->fail('Expected invalid configuration or unsupported amount.');
            } catch (ProviderException) {
                // These conditions must not submit a checkout.
            }
        }
        Http::assertNothingSent();
    }

    public function test_malformed_success_is_uncertain(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'unexpected']])]);
        try {
            (new HandyPayProvider)->createCheckout($this->checkout(), ['api_key' => 'hp_test_one']);
            $this->fail('Expected invalid response.');
        } catch (ProviderException $error) {
            $this->assertSame('invalid_response', $error->reason);
            $this->assertTrue($error->uncertain);
        }
    }

    private function checkout(): CheckoutRequest
    {
        return new CheckoutRequest('opaque-attempt', 1234, 'JMD', 'https://clinic.example.test/success', 'https://clinic.example.test/cancel');
    }

    private function sessionPayload(array $extra = []): array
    {
        return ['success' => true, 'data' => [
            'id' => 'cs_test_checkout', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout',
            'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 1234, 'currency' => 'jmd', ...$extra,
        ]];
    }
}
