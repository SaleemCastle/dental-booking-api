<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PostmanExport;
use Tests\TestCase;

require_once __DIR__.'/../../../tools/postman.php';

class PatientInsuranceContractTest extends TestCase
{
    public function test_contract_covers_only_additive_insurance_routes_and_write_disabled_exports(): void
    {
        $contract = json_decode(file_get_contents(base_path('docs/patient-insurance.openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            $this->assertStringContainsString('/insurance-associations', $path);
            foreach (array_keys($operations) as $method) {
                $expected[] = strtoupper($method).' '.ltrim($path, '/');
            }
        }
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->getActionName(), 'PatientInsuranceController@')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' '.$route->uri();
                }
                $this->assertContains('auth:sanctum', $route->gatherMiddleware());
                $this->assertContains('tenant', $route->gatherMiddleware());
            }
        }
        sort($expected);
        sort($actual);
        $this->assertCount(11, $actual);
        $this->assertSame($expected, $actual);
        $export = (new PostmanExport($contract))->build();
        foreach (['collection' => 'patient-insurance.postman_collection.json', 'environment' => 'patient-insurance.postman_environment.json'] as $key => $name) {
            $this->assertSame(PostmanExport::json($export[$key]), file_get_contents(base_path('docs/postman/'.$name)));
        }
        $this->assertSame('false', collect($export['environment']['values'])->firstWhere('key', 'allow_writes')['value']);
    }
}
