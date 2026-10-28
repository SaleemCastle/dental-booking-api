<?php

namespace App\Billing\Providers;

final class VerifiedPaymentEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $status,
        public readonly ?string $sessionReference = null,
        public readonly ?string $operationKey = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $transactionReference = null,
        public readonly ?bool $liveMode = null,
    ) {}
}
