<?php

namespace App\Exceptions;

use RuntimeException;

final class ConsentBlocked extends RuntimeException
{
    public function __construct(public readonly array $decision)
    {
        parent::__construct('Patient communication is blocked: '.$decision['reason'].'.');
    }
}
