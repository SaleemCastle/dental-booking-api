<?php

namespace Tests\Feature\Api;

use PostmanExport;
use Tests\TestCase;

require_once __DIR__.'/../../../tools/postman.php';

class InsuranceObservationContractTest extends TestCase
{
    public function test_m5_openapi_contracts_match_their_write_disabled_postman_exports(): void
    {
        $domains = [
            'insurance-remittances',
            'insurance-receipts',
            'insurance-obligations',
        ];

        foreach ($domains as $domain) {
            $contract = json_decode(file_get_contents(base_path('docs/'.$domain.'.openapi.json')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('3.0.3', $contract['openapi']);
            $this->assertNotEmpty($contract['paths']);

            $export = (new PostmanExport($contract))->build();
            $this->assertSame('false', collect($export['environment']['values'])->firstWhere('key', 'allow_writes')['value']);
            $collectionJson = PostmanExport::json($export['collection']);
            $this->assertStringContainsString('{{$guid}}', $collectionJson);

            foreach (['collection' => $domain.'.postman_collection.json', 'environment' => $domain.'.postman_environment.json'] as $key => $filename) {
                $this->assertSame(PostmanExport::json($export[$key]), file_get_contents(base_path('docs/postman/'.$filename)), $domain.' '.$key.' export is stale');
            }
        }
    }
}
