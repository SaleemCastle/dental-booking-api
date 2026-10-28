<?php

namespace Tests\Feature;

use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentProviderEvent;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PaymentReconciliationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_existing_encrypted_configuration_and_assigns_an_endpoint(): void
    {
        $migration = require database_path('migrations/2026_10_28_000002_add_payment_reconciliation.php');
        $migration->down();
        $encrypted = Crypt::encryptString(json_encode(['api_key' => 'hp_test_legacy'], JSON_THROW_ON_ERROR));
        $id = DB::table('payment_provider_configurations')->insertGetId([
            'tenant_id' => app(TenantContext::class)->id(), 'provider' => 'handypay', 'credentials' => $encrypted,
            'success_url' => 'https://clinic.example.test/complete', 'cancel_url' => 'https://clinic.example.test/cancel', 'enabled' => true,
        ]);
        $migration->up();
        $configuration = PaymentProviderConfiguration::findOrFail($id);
        $this->assertTrue(Str::isUuid($configuration->webhook_endpoint));
        $this->assertSame(['api_key' => 'hp_test_legacy'], $configuration->credentials);
        $this->assertSame($encrypted, DB::table('payment_provider_configurations')->where('id', $id)->value('credentials'));
        $this->assertNull($configuration->sandbox_verified_at);
    }

    public function test_rollback_refuses_to_discard_provider_event_history(): void
    {
        $configuration = PaymentProviderConfiguration::create([
            'provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_migration'],
            'success_url' => 'https://clinic.example.test/complete', 'cancel_url' => 'https://clinic.example.test/cancel', 'enabled' => true,
        ]);
        PaymentProviderEvent::create([
            'payment_provider_configuration_id' => $configuration->id, 'event_id' => 'evt_history',
            'event_type' => 'checkout.session.expired', 'payload_hash' => str_repeat('a', 64), 'outcome' => 'unmatched',
        ]);
        try {
            (require database_path('migrations/2026_10_28_000002_add_payment_reconciliation.php'))->down();
            $this->fail('Provider history must survive rollback.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('reconcile provider history', $error->getMessage());
        }
        $this->assertDatabaseHas('payment_provider_events', ['event_id' => 'evt_history']);
    }
}
