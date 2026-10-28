<?php

namespace App\Billing\Providers;

final class CheckoutRequest
{
    public function __construct(
        public readonly string $operationKey,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
    ) {}
}
