<?php

namespace App\Documents;

use Illuminate\Http\UploadedFile;

interface EvidenceScanner
{
    /** Trusted server integration only; never accept verdicts or policy references from HTTP input.
     * @return array{status: string, reference: ?string}
     */
    public function scan(UploadedFile $file): array;
}
