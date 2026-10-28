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
            ->expectsQuestion('Webhook signing secret', 'sandbox_webhook_secret')
            ->expectsOutput('Clinic payment provider configured. Credentials are encrypted and hidden from API responses.')
            ->assertSuccessful();
        $this->assertDatabaseHas('payment_provider_configurations', ['tenant_id' => $tenant->id, 'provider' => 'handypay', 'enabled' => true]);
        $raw = DB::table('payment_provider_configurations')->where('tenant_id', $tenant->id)->value('credentials');
        $this->assertStringNotContainsString('hp_test_new_secret', $raw);
        $this->assertSame(0, PaymentProviderConfiguration::count());
        $configuration = app(TenantContext::class)->run($tenant, fn () => PaymentProviderConfiguration::firstOrFail());
        $this->assertSame(['api_key' => 'hp_test_new_secret', 'webhook_secret' => 'sandbox_webhook_secret'], $configuration->credentials);
        $this->assertArrayNotHasKey('credentials', $configuration->toArray());
    }

    public function test_live_credentials_are_refused_before_a_sandbox_settlement(): void
    {
        $tenant = Tenant::factory()->create();
        try {
            $this->artisan('billing:configure-provider', [
                'tenant' => $tenant->id, '--success-url' => 'https://clinic.example.test/complete', '--cancel-url' => 'https://clinic.example.test/cancel',
            ])->expectsQuestion('Merchant API key', 'hp_live_unverified')
                ->expectsQuestion('Webhook signing secret', 'live_webhook_secret')->run();
            $this->fail('Live provisioning must require a sandbox settlement.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('provider', $error->errors());
        }
        $this->assertDatabaseCount('payment_provider_configurations', 0);
    }

    public function test_verified_sandbox_configuration_is_preserved_when_a_live_revision_is_provisioned(): void
    {
        $tenant = Tenant::factory()->create();
        $old = app(TenantContext::class)->run($tenant, function () {
            $configuration = new PaymentProviderConfiguration([
                'provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_old', 'webhook_secret' => 'sandbox_webhook_secret'],
                'success_url' => 'https://clinic.example.test/complete', 'cancel_url' => 'https://clinic.example.test/cancel', 'enabled' => true,
            ]);
            $configuration->sandbox_verified_at = now();
            $configuration->save();

            return $configuration;
        });
        $this->artisan('billing:configure-provider', [
            'tenant' => $tenant->id, '--success-url' => 'https://clinic.example.test/complete', '--cancel-url' => 'https://clinic.example.test/cancel',
        ])->expectsQuestion('Merchant API key', 'hp_live_verified')
            ->expectsQuestion('Webhook signing secret', 'live_webhook_secret')->assertSuccessful();
        $this->assertFalse($old->refresh()->enabled);
        $this->assertSame('hp_test_old', $old->credentials['api_key']);
        $next = app(TenantContext::class)->run($tenant, fn () => PaymentProviderConfiguration::where('enabled', true)->sole());
        $this->assertSame('hp_live_verified', $next->credentials['api_key']);
        $this->assertNotSame($old->webhook_endpoint, $next->webhook_endpoint);
        $this->assertDatabaseCount('payment_provider_configurations', 2);
    }
}
