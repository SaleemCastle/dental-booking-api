<?php

if ($argc !== 3 || ! is_file($argv[1]) || ! ctype_digit($argv[2])) {
    fwrite(STDERR, "Usage: php tools/check-claim-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$xml = simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, "Could not parse Clover coverage report.\n");
    exit(2);
}

$expected = ['InsuranceClaimAccessPolicy.php', 'InsuranceClaimLifecycle.php'];
$files = [];
$covered = 0;
$total = 0;
foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = basename((string) $file['name']);
    if (! in_array($name, $expected, true)) {
        continue;
    }
    $files[] = $name;
    foreach ($file->xpath('./line[@type="stmt"]') ?: [] as $line) {
        $total++;
        if ((int) $line['count'] > 0) {
            $covered++;
        }
    }
}

sort($files);
sort($expected);
if ($files !== $expected || $total === 0) {
    fwrite(STDERR, "Coverage report is missing one or more claim domain files.\n");
    exit(2);
}

$percent = 100 * $covered / $total;
printf("Claim lifecycle/access line coverage: %.2f%% (%d/%d statements)\n", $percent, $covered, $total);
if ($percent < (float) $argv[2]) {
    fwrite(STDERR, "Claim domain coverage is below the configured threshold.\n");
    exit(1);
}
