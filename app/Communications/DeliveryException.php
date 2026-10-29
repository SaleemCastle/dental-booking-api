<?php

namespace App\Communications;

use RuntimeException;

final class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly bool $uncertain = false)
    {
        parent::__construct($reason);
    }
}
