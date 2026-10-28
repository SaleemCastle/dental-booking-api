<?php

namespace App\Billing\Providers;

use DateTimeImmutable;

/** Normalized status: active, pending, completed, expired, cancelled or failed. Never settles the ledger. */
final class CheckoutResult
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $checkoutUrl,
        public readonly string $status,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
    ) {}
}
