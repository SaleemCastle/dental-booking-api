<?php

// Live integration check: writes a random, nonclinical object and removes it.
// Run with: php tools/check-document-storage.php

use App\Documents\DocumentStorage;
use App\Models\PatientDocument;
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: '.$error->getMessage().PHP_EOL);
    exit(1);
});

$storage = $app->make(DocumentStorage::class);
$diskName = $storage->diskName();
$diskConfig = config('filesystems.disks.'.$diskName);
if (empty($diskConfig['bucket']) || $diskConfig['visibility'] !== 'private') {
    throw new RuntimeException('Configure the dedicated private patient-document bucket first.');
}

$key = 'tenants/_storage-check/'.Str::uuid();
$document = (new PatientDocument)->forceFill(['disk' => $diskName, 'storage_key' => $key]);
$temporaryPath = tempnam(sys_get_temp_dir(), 'dental-storage-');
if ($temporaryPath === false) {
    throw new RuntimeException('Unable to create the temporary check file.');
}

$contents = "%PDF-1.4\n% Nonclinical storage connectivity check\n%%EOF\n";
$written = false;
$stream = null;
try {
    if (file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
        throw new RuntimeException('Unable to write the temporary check file.');
    }
    $file = new UploadedFile($temporaryPath, 'storage-check.pdf', 'application/pdf', null, true);
    $storage->put($key, $file);
    $written = true;
    echo "PASS: upload through the application document adapter\n";

    $stream = $storage->read($document);
    if (stream_get_contents($stream) !== $contents) {
        throw new RuntimeException('Downloaded bytes differ from uploaded bytes.');
    }
    fclose($stream);
    $stream = null;
    echo "PASS: private download preserves file contents\n";

    $disk = Storage::disk($diskName);
    $head = $disk->getClient()->headObject(['Bucket' => $diskConfig['bucket'], 'Key' => $key]);
    if ($head['ServerSideEncryption'] !== $diskConfig['options']['ServerSideEncryption']) {
        throw new RuntimeException('Object encryption does not match configuration.');
    }
    if ($head['ContentType'] !== 'application/pdf') {
        throw new RuntimeException('Object content type does not match the uploaded PDF.');
    }
    echo "PASS: encryption at rest and detected content type\n";

    $response = (new Client)->get($disk->url($key), ['http_errors' => false, 'timeout' => 20]);
    if ($response->getStatusCode() !== 403) {
        throw new RuntimeException('Expected anonymous object access to return 403.');
    }
    echo "PASS: anonymous object download denied (403)\n";
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
    try {
        if ($written) {
            $storage->delete($document);
            if (Storage::disk($diskName)->exists($key)) {
                throw new RuntimeException('The temporary check object still exists after deletion.');
            }
            echo "PASS: temporary object deleted\n";
        }
    } finally {
        unlink($temporaryPath);
    }
}
