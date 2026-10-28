<?php

namespace App\Billing\Providers;

use RuntimeException;

/** Contains only safe machine categories, never upstream bodies, URLs or credentials. */
final class ProviderException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable', public readonly bool $uncertain = false)
    {
        parent::__construct('Payment provider request failed.');
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            'invalid_signature' => 401,
            'invalid_event' => 400,
            'not_configured', 'unavailable', 'rate_limited' => 503,
            'timeout' => 504,
            'unsupported', 'reconciliation_required', 'in_progress', 'sandbox_required' => 409,
            default => 502,
        };
    }

    public function apiCode(): string
    {
        return match ($this->reason) {
            'invalid_signature' => 'PAYMENT_WEBHOOK_INVALID_SIGNATURE',
            'invalid_event' => 'PAYMENT_WEBHOOK_INVALID_EVENT',
            'sandbox_required' => 'PAYMENT_SANDBOX_VERIFICATION_REQUIRED',
            'not_configured' => 'PAYMENT_PROVIDER_NOT_CONFIGURED',
            'timeout' => 'PAYMENT_PROVIDER_TIMEOUT',
            'rate_limited' => 'PAYMENT_PROVIDER_RATE_LIMITED',
            'unsupported' => 'PAYMENT_PROVIDER_OPERATION_UNSUPPORTED',
            'reconciliation_required' => 'PAYMENT_LINK_RECONCILIATION_REQUIRED',
            'in_progress' => 'PAYMENT_LINK_IN_PROGRESS',
            default => 'PAYMENT_PROVIDER_ERROR',
        };
    }
}
