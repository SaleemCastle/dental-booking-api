<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PostmanExport;
use Tests\TestCase;

require_once __DIR__.'/../../../tools/postman.php';

class InsuranceCatalogContractTest extends TestCase
{
    public function test_contract_covers_every_new_route_and_exports_are_reproducible(): void
    {
        $contract = json_decode(file_get_contents(base_path('docs/insurance-catalog.openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($contract['paths'] as $path => $definition) {
            foreach (array_keys($definition) as $method) {
                if (in_array($method, ['get', 'post', 'patch', 'delete'], true)) {
                    $expected[] = strtoupper($method).' '.ltrim($path, '/');
                }
            }
        }
        $this->assertCount(17, $expected);
        // This namespace also includes M1-C evidence and M2-B benefits. Keep
        // exact coverage of every insurance route using their owning contracts.
        foreach (['insurance-evidence', 'insurance-benefits'] as $domain) {
            $additional = json_decode(file_get_contents(base_path('docs/'.$domain.'.openapi.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($additional['paths'] as $path => $definition) {
                if (str_starts_with($path, '/api/insurance/')) {
                    foreach (array_keys($definition) as $method) {
                        if (in_array($method, ['get', 'post', 'patch', 'delete'], true)) {
                            $expected[] = strtoupper($method).' '.ltrim($path, '/');
                        }
                    }
                }
            }
        }
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/insurance/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' '.$route->uri();
                }
            }
        }
        sort($expected);
        sort($actual);
        $this->assertCount(27, $expected);
        $this->assertSame($expected, $actual);
        $export = (new PostmanExport($contract))->build();
        foreach (['collection' => 'insurance-catalog.postman_collection.json', 'environment' => 'insurance-catalog.postman_environment.json'] as $key => $name) {
            $this->assertSame(PostmanExport::json($export[$key]), file_get_contents(base_path('docs/postman/'.$name)));
        }
        $this->assertSame('false', collect($export['environment']['values'])->firstWhere('key', 'allow_writes')['value']);
    }
}
