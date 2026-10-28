<?php

namespace App\Billing\Providers;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** HandyPay Ecommerce API 2025-01-01, https://api.handypay.me/api/openapi.json */
final class HandyPayProvider implements PaymentProvider
{
    private const BASE_URL = 'https://api.handypay.me/api/v1';

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
            || (isset($data['expires_at']) && ! is_int($data['expires_at']))) {
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
        );
    }
}
