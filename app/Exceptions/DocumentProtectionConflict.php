<?php

namespace App\Exceptions;

use RuntimeException;

class DocumentProtectionConflict extends RuntimeException
{
    public function __construct(public readonly string $apiCode = 'DOCUMENT_RETENTION_BLOCKED')
    {
        parent::__construct('Document protection prevents this operation.');
    }
}
