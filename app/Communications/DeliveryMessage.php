<?php

namespace App\Communications;

final class DeliveryMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $operationKey,
    ) {}
}
