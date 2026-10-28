<?php

namespace App\Billing\Providers;

use DateTimeImmutable;

/** Normalized server-to-server result. Settlement additionally requires amount and currency. */
final class CheckoutResult
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $checkoutUrl,
        public readonly string $status,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $transactionReference = null,
    ) {}
}
