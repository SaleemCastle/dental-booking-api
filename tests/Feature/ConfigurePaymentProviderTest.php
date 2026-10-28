<?php

namespace Tests\Feature;

use App\Models\PaymentProviderConfiguration;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigurePaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_provisions_tenant_credentials_without_exposing_them_in_output_or_database(): void
    {
        $tenant = Tenant::factory()->create();
        $this->artisan('billing:configure-provider', [
            'tenant' => $tenant->id, 'provider' => 'handypay',
            '--success-url' => 'https://clinic.example.test/complete', '--cancel-url' => 'https://clinic.example.test/cancel',
        ])->expectsQuestion('Merchant API key', 'hp_test_new_secret')
            ->expectsOutput('Clinic payment provider configured. Credentials are encrypted and hidden from API responses.')
            ->assertSuccessful();
        $this->assertDatabaseHas('payment_provider_configurations', ['tenant_id' => $tenant->id, 'provider' => 'handypay', 'enabled' => true]);
        $raw = DB::table('payment_provider_configurations')->where('tenant_id', $tenant->id)->value('credentials');
        $this->assertStringNotContainsString('hp_test_new_secret', $raw);
        $this->assertSame(0, PaymentProviderConfiguration::count());
        $configuration = app(TenantContext::class)->run($tenant, fn () => PaymentProviderConfiguration::firstOrFail());
        $this->assertSame(['api_key' => 'hp_test_new_secret'], $configuration->credentials);
        $this->assertArrayNotHasKey('credentials', $configuration->toArray());
    }
}
