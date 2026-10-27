<?php

namespace App\Documents;

use App\Models\PatientDocument;
use Illuminate\Http\UploadedFile;

interface DocumentStorage
{
    public function diskName(): string;

    public function put(string $key, UploadedFile $file): void;

    /** @return resource */
    public function read(PatientDocument $document);

    public function delete(PatientDocument $document): void;
}
