<?php

namespace Tests\Feature\Api;

use App\Insurance\BenefitInput;
use Illuminate\Support\Facades\Route;
use PostmanExport;
use Tests\TestCase;

require_once __DIR__.'/../../../tools/postman.php';

class InsuranceBenefitContractTest extends TestCase
{
    public function test_routes_and_safe_exports_match_the_contract(): void
    {
        $contract = json_decode(file_get_contents(base_path('docs/insurance-benefits.openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.ltrim($path, '/');
            }
        }
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->getActionName(), 'InsuranceBenefitController@')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' '.$route->uri();
                }
                $this->assertContains('auth:sanctum', $route->gatherMiddleware());
                $this->assertContains('tenant', $route->gatherMiddleware());
            }
        }
        sort($expected);
        sort($actual);
        $this->assertCount(6, $actual);
        $this->assertSame($expected, $actual);
        $export = (new PostmanExport($contract))->build();
        foreach (['collection' => 'insurance-benefits.postman_collection.json', 'environment' => 'insurance-benefits.postman_environment.json'] as $key => $name) {
            $this->assertSame(PostmanExport::json($export[$key]), file_get_contents(base_path('docs/postman/'.$name)));
        }
        $this->assertSame('false', collect($export['environment']['values'])->firstWhere('key', 'allow_writes')['value']);
        foreach ($export['collection']['item'][0]['item'] as $item) {
            if ($item['request']['method'] === 'POST') {
                $input = json_decode($item['request']['body']['raw'], true, 512, JSON_THROW_ON_ERROR);
                if (isset($input['expected_version'])) {
                    $input['expected_version'] = 1; // Deliberately supplied by the operator, never auto-selected.
                }
                $this->assertNotEmpty(BenefitInput::validate($input, $input['source'] === 'insurer_plan'));
            }
        }
    }
}
