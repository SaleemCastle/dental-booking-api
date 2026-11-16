<?php

require_once __DIR__.'/postman.php';

$contract = json_decode(file_get_contents(__DIR__.'/../docs/patient-insurance.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
$export = (new PostmanExport($contract))->build();
$mode = $argv[1] ?? '--check';
if (! in_array($mode, ['--write', '--check'], true)) {
    fwrite(STDERR, "Usage: php tools/patient-insurance-postman.php [--write|--check]\n");
    exit(1);
}
foreach (['collection' => 'patient-insurance.postman_collection.json', 'environment' => 'patient-insurance.postman_environment.json'] as $key => $name) {
    $path = __DIR__.'/../docs/postman/'.$name;
    $json = PostmanExport::json($export[$key]);
    if ($mode === '--write') {
        file_put_contents($path, $json);
    } elseif (! is_file($path) || file_get_contents($path) !== $json) {
        fwrite(STDERR, 'Patient insurance export is stale: '.$name."\n");
        exit(1);
    }
}
echo "Patient insurance contract/export check completed.\n";
