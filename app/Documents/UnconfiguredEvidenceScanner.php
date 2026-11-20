<?php

namespace App\Documents;

use Illuminate\Http\UploadedFile;

class UnconfiguredEvidenceScanner implements EvidenceScanner
{
    public function scan(UploadedFile $file): array
    {
        return ['status' => 'pending', 'reference' => null];
    }
}
