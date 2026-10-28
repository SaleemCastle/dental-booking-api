<?php

namespace App\Billing\Providers;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** HandyPay Ecommerce API 2025-01-01, https://api.handypay.me/api/openapi.json */
final class HandyPayProvider implements WebhookPaymentProvider
{
    private const BASE_URL = 'https://api.handypay.me/api/v1';

    public function verifyWebhook(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature, #[\SensitiveParameter] array $credentials): VerifiedPaymentEvent
    {
        $secret = $credentials['webhook_secret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new ProviderException('not_configured');
        }
        if (! preg_match('/\Asha256=([a-fA-F0-9]{64})\z/', $signature, $matches)
            || ! hash_equals(hash_hmac('sha256', $body, $secret), strtolower($matches[1]))) {
            throw new ProviderException('invalid_signature');
        }
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_event');
        }
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! preg_match('/\Aevt_[A-Za-z0-9_]{1,240}\z/', $event['id'])
            || ! is_string($event['type'] ?? null) || ! preg_match('/\A[a-z_.]{1,100}\z/', $event['type'])
            || ! is_array($event['data'] ?? null) || (isset($event['livemode']) && ! is_bool($event['livemode']))) {
            throw new ProviderException('invalid_event');
        }
        $data = $event['data']; // HandyPay forwards the resource directly in data, not data.object.
        $status = match ($event['type']) {
            'checkout.session.completed' => ($data['payment_status'] ?? null) === 'paid' ? 'completed' : 'pending',
            'checkout.session.async_payment_succeeded', 'payment_intent.succeeded' => 'completed',
            'checkout.session.expired' => 'expired',
            'checkout.session.async_payment_failed' => 'failed',
            // A declined card can be retried on the same hosted checkout; keep it outstanding.
            'payment_intent.payment_failed' => ($data['status'] ?? null) === 'canceled' ? 'cancelled' : 'pending',
            default => null,
        };
        if ($status === null) {
            return new VerifiedPaymentEvent($event['id'], $event['type'], null);
        }
        $session = str_starts_with($event['type'], 'checkout.session.');
        $reference = $data['id'] ?? null;
        $operation = $data['metadata']['operation_key'] ?? null;
        $amount = $session ? ($data['amount_total'] ?? null) : ($status === 'completed' ? ($data['amount_received'] ?? null) : null);
        $currency = $data['currency'] ?? null;
        $transaction = $session ? ($data['payment_intent'] ?? $reference) : $reference;
        if (! is_string($reference) || ! preg_match($session ? '/\Acs_[A-Za-z0-9_]{1,240}\z/' : '/\Api_[A-Za-z0-9_]{1,240}\z/', $reference)
            || ($operation !== null && (! is_string($operation) || ! \Illuminate\Support\Str::isUuid($operation)))
            || ! is_string($transaction) || ! preg_match('/\A(?:pi|cs)_[A-Za-z0-9_]{1,240}\z/', $transaction)
            || ($amount !== null && (! is_int($amount) || $amount < 0 || $amount > 99999999))
            || ($currency !== null && (! is_string($currency) || ! preg_match('/\A[A-Za-z]{3}\z/', $currency)))
            || ($status === 'completed' && ($amount === null || $currency === null
                || ($session && ($data['payment_status'] ?? null) !== 'paid')
                || (! $session && ($data['status'] ?? null) !== 'succeeded')))) {
            throw new ProviderException('invalid_event');
        }

        return new VerifiedPaymentEvent(
            $event['id'], $event['type'], $status, $session ? $reference : null, $operation,
            $amount, $currency === null ? null : strtoupper($currency), $transaction, $event['livemode'] ?? null,
        );
    }

    public function createCheckout(CheckoutRequest $request, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        // P2 uses two-decimal currencies. Fail closed for other currency exponents.
        if (! in_array($request->currency, ['USD', 'JMD'], true) || $request->amountMinor < 1 || $request->amountMinor > 99999999) {
            throw new ProviderException('rejected');
        }

        return $this->send('POST', '/payment-sessions', $credentials, [
            'line_items' => [[
                'amount' => $request->amountMinor, 'currency' => strtolower($request->currency),
                'name' => 'Clinic invoice', 'quantity' => 1,
            ]],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'pass_fees_to_customer' => false,
            'metadata' => ['operation_key' => $request->operationKey],
        ]);
    }

    public function checkoutStatus(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        return $this->send('GET', '/payment-sessions/'.rawurlencode($reference), $credentials);
    }

    public function cancelCheckout(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        // The current hosted-session API has no documented cancellation endpoint.
        throw new ProviderException('unsupported');
    }

    private function send(string $method, string $path, #[\SensitiveParameter] array $credentials, array $body = []): CheckoutResult
    {
        $key = $credentials['api_key'] ?? null;
        if (! is_string($key) || ! preg_match('/\Ahp_(test|live)_[A-Za-z0-9_-]+\z/', $key)) {
            throw new ProviderException('not_configured');
        }
        try {
            // No automatic retries or redirects: sessions have no documented idempotency support.
            $response = Http::withToken($key)->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->send($method, self::BASE_URL.$path, $method === 'POST' ? ['json' => $body] : []);
        } catch (ConnectionException) {
            throw new ProviderException('timeout', uncertain: $method === 'POST');
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $reason = match ($response->status()) {
                401, 403 => 'authentication',
                429 => 'rate_limited',
                400, 422 => 'rejected',
                409 => 'reconciliation_required',
                default => 'unavailable',
            };
            throw new ProviderException($reason, uncertain: $method === 'POST' && ! in_array($response->status(), [400, 401, 403, 422, 429], true));
        }

        $data = $response->json('data');
        if (! is_array($data) || ! is_string($data['id'] ?? null)
            || ! preg_match('/\Acs_[A-Za-z0-9_]+\z/', $data['id'])
            || ! in_array($data['status'] ?? null, ['open', 'complete', 'expired'], true)
            || (isset($data['amount_total']) && ! is_int($data['amount_total']))
            || (isset($data['currency']) && (! is_string($data['currency']) || ! preg_match('/\A[a-zA-Z]{3}\z/', $data['currency'])))
            || (isset($data['expires_at']) && ! is_int($data['expires_at']))
            || (isset($data['payment_intent']) && (! is_string($data['payment_intent']) || ! preg_match('/\Api_[A-Za-z0-9_]{1,240}\z/', $data['payment_intent'])))) {
            throw new ProviderException('invalid_response', uncertain: $method === 'POST');
        }

        $status = match ($data['status']) {
            'open' => 'active',
            'complete' => ($data['payment_status'] ?? null) === 'paid' ? 'completed' : 'pending',
            'expired' => 'expired',
        };

        return new CheckoutResult(
            $data['id'], is_string($data['url'] ?? null) ? $data['url'] : null, $status,
            isset($data['expires_at']) ? new DateTimeImmutable('@'.$data['expires_at']) : null,
            $data['amount_total'] ?? null, isset($data['currency']) ? strtoupper($data['currency']) : null,
            $data['payment_intent'] ?? $data['id'],
        );
    }
}
