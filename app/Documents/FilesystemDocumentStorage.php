<?php

namespace App\Documents;

use App\Models\PatientDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class FilesystemDocumentStorage implements DocumentStorage
{
    public function diskName(): string
    {
        return config('patient_documents.disk');
    }

    public function put(string $key, UploadedFile $file): void
    {
        $this->assertCanonicalKey($key);
        if (PatientDocument::withoutGlobalScopes()->where('storage_key', $key)->exists()) {
            throw new LogicException('Accepted document object keys are write-once.');
        }
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new ServiceUnavailableHttpException;
        }
        try {
            if (! $this->disk($this->diskName())->put($key, $stream, ['ContentType' => DocumentUpload::mimeType($file)])) {
                throw new ServiceUnavailableHttpException;
            }
        } finally {
            fclose($stream);
        }
    }

    public function read(PatientDocument $document)
    {
        $stream = $this->disk($document->disk)->readStream($document->storage_key);
        if (! is_resource($stream)) {
            throw new ServiceUnavailableHttpException;
        }

        return $stream;
    }

    public function delete(PatientDocument $document): void
    {
        $this->assertCanonicalKey($document->storage_key);
        app(DocumentProtection::class)->assertStoragePurge($document);
        if (! $this->disk($document->disk)->delete($document->storage_key)) {
            throw new ServiceUnavailableHttpException;
        }
    }

    private function assertCanonicalKey(string $key): void
    {
        if ($key === '' || (new \League\Flysystem\WhitespacePathNormalizer)->normalizePath($key) !== $key) {
            throw new LogicException('Document object keys must be canonical.');
        }
    }

    private function disk(string $name): FilesystemAdapter
    {
        // Never silently fall back to FILESYSTEM_DISK or the public disk.
        if ($name !== $this->diskName() || config("filesystems.disks.$name.visibility") !== 'private') {
            throw new LogicException('Patient document storage must use its dedicated private disk.');
        }

        return Storage::disk($name);
    }
}
