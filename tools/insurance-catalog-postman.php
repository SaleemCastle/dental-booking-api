<?php

require_once __DIR__.'/postman.php';

$contract = json_decode(file_get_contents(__DIR__.'/../docs/insurance-catalog.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
$export = (new PostmanExport($contract))->build();
$mode = $argv[1] ?? '--check';
if (! in_array($mode, ['--write', '--check'], true)) {
    fwrite(STDERR, "Usage: php tools/insurance-catalog-postman.php [--write|--check]\n");
    exit(1);
}
foreach (['collection' => 'insurance-catalog.postman_collection.json', 'environment' => 'insurance-catalog.postman_environment.json'] as $key => $name) {
    $path = __DIR__.'/../docs/postman/'.$name;
    $json = PostmanExport::json($export[$key]);
    if ($mode === '--write') {
        file_put_contents($path, $json);
    } elseif (! is_file($path) || file_get_contents($path) !== $json) {
        fwrite(STDERR, 'Insurance catalog export is stale: '.$name."\n");
        exit(1);
    }
}
echo "Insurance catalog contract/export check completed.\n";
