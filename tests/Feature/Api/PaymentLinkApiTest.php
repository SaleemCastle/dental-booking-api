<?php

namespace Tests\Feature\Api;

use App\Billing\Providers\ProviderException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PaymentLinkWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakePaymentProvider;
use Tests\TestCase;

class PaymentLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakePaymentProvider;
        $this->app->instance(FakePaymentProvider::class, $this->provider);
        config(['billing.providers.handypay' => FakePaymentProvider::class]);
    }

    public function test_creation_uses_committed_invoice_balance_currency_and_encrypted_tenant_configuration(): void
    {
        $actor = $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create(['amount_due' => '100.37', 'currency' => 'JMD']);
        app(\App\Services\BillingWorkflow::class)->recordPayment([
            'invoice_id' => $invoice->id, 'amount' => '25.00', 'currency' => 'JMD',
            'payment_method' => 'cash', 'idempotency_key' => 'prior-payment',
        ], $actor);
        $response = $this->createLink($invoice)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.payment_link.provider', 'handypay')
            ->assertJsonPath('data.payment_link.amount', '75.37')->assertJsonPath('data.payment_link.currency', 'JMD')
            ->assertJsonPath('data.payment_link.status', 'active')->assertJsonMissingPath('data.payment_link.credentials');
        $link = PaymentLink::findOrFail($response->json('data.payment_link.id'));
        $this->assertNotNull($link->external_reference);
        $this->assertNotNull($link->expires_at);
        $sent = $this->provider->created[0];
        $this->assertSame(7537, $sent['request']->amountMinor);
        $this->assertSame('JMD', $sent['request']->currency);
        $this->assertSame(['api_key' => 'hp_test_clinic_secret'], $sent['credentials']);
        $this->assertSame('https://clinic.example.test/complete', $sent['request']->successUrl);
        $raw = DB::table('payment_provider_configurations')->where('id', $configuration->id)->value('credentials');
        $this->assertStringNotContainsString('hp_test_clinic_secret', $raw);
        $this->assertStringNotContainsString('hp_test_clinic_secret', $configuration->toJson());
        $this->assertSame('25.00', $invoice->refresh()->amount_paid);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'resource_type' => 'payment_links', 'resource_id' => (string) $link->id, 'actor_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'active', 'resource_type' => 'payment_links']);
        $audits = AuditLog::where('resource_type', 'payment_links')->get()->toJson();
        foreach (['75.37', 'hp_test_clinic_secret', $link->checkout_url, $link->external_reference] as $secret) {
            $this->assertStringNotContainsString($secret, $audits);
        }
        $this->getJson('/api/payment-links/'.$link->id)->assertOk()->assertJsonPath('data.payment_link.id', $link->id);
        $this->getJson('/api/invoices/'.$invoice->id.'/payment-links?per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_idempotent_retries_never_dispatch_twice_or_create_more_audits_even_after_cancellation(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $audits = AuditLog::count();
        $this->createLink($invoice)->assertOk()->assertJsonPath('data.payment_link.id', $id);
        $this->assertSame($audits, AuditLog::count());
        $this->createLink($invoice, 'other')->assertUnprocessable();
        $this->assertCount(1, $this->provider->created);
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertOk()->assertJsonPath('data.payment_link.status', 'cancelled')
            ->assertJsonPath('data.payment_link.checkout_url', null);
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertOk();
        $this->assertCount(1, $this->provider->cancellations);
        $audits = AuditLog::count();
        $this->createLink($invoice)->assertOk()->assertJsonPath('data.payment_link.status', 'cancelled');
        $this->assertSame($audits, AuditLog::count());
        $this->createLink($invoice, 'checkout-a')->assertCreated();
        $this->assertCount(2, $this->provider->created);
    }

    public function test_keys_are_case_sensitive_and_scoped_to_each_invoice_and_tenant(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice, 'Checkout-A')->assertCreated()->json('data.payment_link.id');
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertOk();
        $this->createLink($invoice, 'checkout-a')->assertCreated();
        $this->createLink(Invoice::factory()->issued()->create(), 'Checkout-A')->assertCreated();
        $this->assertDatabaseCount('payment_links', 3);
    }

    public static function unsupportedFields(): array
    {
        return [
            ['amount', '1.00'], ['amount_minor', 1], ['currency', 'USD'], ['provider', 'ezeepayments'],
            ['tenant_id', 1], ['status', 'completed'], ['external_reference', 'chosen'],
            ['success_url', 'https://attacker.example'], ['expires_at', 'tomorrow'],
            ['card_number', '4111111111111111'], ['cvv', '123'], ['expiry', '12/30'],
            ['card', ['number' => '4111111111111111']], ['metadata', ['pan' => '4111111111111111']],
            ['idempotency_key', ''], ['idempotency_key', 'contains spaces'],
        ];
    }

    #[DataProvider('unsupportedFields')]
    public function test_amount_overrides_card_data_and_unknown_fields_are_rejected_without_side_effects(string $field, mixed $value): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $this->postJson($this->url($invoice), array_replace(['idempotency_key' => 'link'], [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors($field);
        $this->assertCount(0, $this->provider->created);
        $this->assertDatabaseCount('payment_links', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_only_issued_unpaid_invoices_accept_links_and_missing_or_disabled_configuration_fails_safely(): void
    {
        $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        $this->createLink($invoice)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_PROVIDER_NOT_CONFIGURED');
        $config = $this->configuration(['enabled' => false]);
        $this->createLink($invoice)->assertStatus(503);
        $config->update(['enabled' => true]);
        $draft = Invoice::factory()->create();
        $this->createLink($draft)->assertUnprocessable();
        $this->patchJson('/api/invoices/'.$draft->id, ['status' => 'void', 'void_reason' => 'Duplicate'])->assertOk();
        $this->createLink($draft)->assertUnprocessable();
        $paid = Invoice::factory()->issued()->withPayment(100)->create();
        $this->createLink($paid)->assertUnprocessable();
        $this->assertDatabaseCount('payment_links', 0);
        $this->assertCount(0, $this->provider->created);
    }

    public static function providerFailures(): array
    {
        return [
            ['timeout', true, 504, 'PAYMENT_PROVIDER_TIMEOUT', 'unknown'],
            ['authentication', false, 502, 'PAYMENT_PROVIDER_ERROR', 'failed'],
            ['rate_limited', false, 503, 'PAYMENT_PROVIDER_RATE_LIMITED', 'failed'],
            ['unavailable', true, 503, 'PAYMENT_PROVIDER_ERROR', 'unknown'],
            ['rejected', false, 502, 'PAYMENT_PROVIDER_ERROR', 'failed'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_failures_use_standard_errors_and_preserve_attempt_for_safe_retries(string $reason, bool $uncertain, int $status, string $code, string $storedStatus): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $this->provider->failure = new ProviderException($reason, $uncertain);
        $this->createLink($invoice)->assertStatus($status)->assertJsonPath('code', $code)
            ->assertJsonPath('success', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('payment_links', ['invoice_id' => $invoice->id, 'status' => $storedStatus]);
        $this->assertDatabaseHas('audit_logs', ['action' => $storedStatus, 'resource_type' => 'payment_links']);
        $this->assertDatabaseCount('payments', 0);
        $this->provider->failure = null;
        $retry = $this->createLink($invoice);
        if ($uncertain) {
            $retry->assertStatus(409)->assertJsonPath('code', 'PAYMENT_LINK_RECONCILIATION_REQUIRED');
            $this->createLink($invoice, 'different')->assertUnprocessable();
        } else {
            $retry->assertOk()->assertJsonPath('data.payment_link.status', 'failed');
            $this->createLink($invoice, 'different')->assertCreated();
        }
        $this->assertCount($uncertain ? 1 : 2, $this->provider->created);
    }

    public function test_existing_in_progress_attempt_blocks_duplicate_dispatch_and_distinct_keys(): void
    {
        $this->actor();
        $config = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $invoice->paymentLinks()->create([
            'payment_provider_configuration_id' => $config->id, 'provider' => 'handypay', 'operation_key' => fake()->uuid(),
            'idempotency_key' => 'link', 'amount' => '100.00', 'currency' => 'USD', 'status' => 'creating',
        ]);
        $this->createLink($invoice)->assertStatus(409)->assertJsonPath('code', 'PAYMENT_LINK_IN_PROGRESS');
        $this->createLink($invoice, 'new')->assertUnprocessable();
        $this->assertCount(0, $this->provider->created);
    }

    public function test_verified_status_refresh_settles_the_ledger_once(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $audits = AuditLog::count();
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertOk();
        $this->assertSame($audits, AuditLog::count());
        $this->provider->status = 'pending';
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertOk()->assertJsonPath('data.payment_link.status', 'pending');
        $this->provider->status = 'completed';
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertOk()->assertJsonPath('data.payment_link.status', 'completed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'completed', 'resource_type' => 'payment_links']);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame('100.00', $invoice->amount_paid);
        $this->assertDatabaseCount('payments', 1);
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->createLink($invoice, 'another')->assertUnprocessable();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertUnprocessable();
    }

    public function test_expiry_is_provider_confirmed_and_allows_a_new_link(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $this->travel(2)->hours();
        $this->createLink($invoice, 'new')->assertUnprocessable();
        $this->provider->status = 'expired';
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertOk()->assertJsonPath('data.payment_link.status', 'expired');
        $this->provider->status = 'active';
        $this->createLink($invoice, 'new')->assertCreated();
    }

    public function test_voiding_and_manual_payment_cannot_leave_a_payable_stale_checkout(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $this->patchJson('/api/invoices/'.$invoice->id, ['status' => 'void', 'void_reason' => 'Duplicate'])->assertUnprocessable();
        $payment = ['invoice_id' => $invoice->id, 'amount' => '25', 'currency' => 'USD', 'payment_method' => 'cash', 'idempotency_key' => 'cash'];
        $this->postJson('/api/payments', $payment)->assertUnprocessable();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertOk();
        $this->postJson('/api/payments', $payment)->assertCreated();
        $this->createLink($invoice, 'remaining')->assertCreated()->assertJsonPath('data.payment_link.amount', '75.00');
        // Retrying an existing ledger payment is safe even while a new link is active.
        $this->postJson('/api/payments', $payment)->assertOk();
    }

    public function test_cancellation_failure_and_unsafe_response_never_claim_success(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $this->provider->failure = new ProviderException('unsupported');
        $audits = AuditLog::count();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertStatus(409)->assertJsonPath('code', 'PAYMENT_PROVIDER_OPERATION_UNSUPPORTED');
        $this->assertSame('active', PaymentLink::find($id)->status);
        $this->assertSame($audits, AuditLog::count());
        $this->provider->failure = null;
        $this->provider->urlOverride = 'http://checkout.example.test/insecure';
        $other = Invoice::factory()->issued()->create();
        $this->createLink($other)->assertStatus(502);
        $this->assertDatabaseHas('payment_links', ['invoice_id' => $other->id, 'status' => 'unknown', 'checkout_url' => null]);
    }

    public function test_authorization_and_foreign_routes_or_direct_calls_cannot_access_another_clinic(): void
    {
        $invoice = Invoice::factory()->issued()->create();
        $this->createLink($invoice)->assertUnauthorized();
        $this->actor(Role::DENTIST);
        $this->configuration();
        $this->createLink($invoice)->assertForbidden();
        $this->actor(Role::BILLING);
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $this->actor(Role::AUDITOR);
        $this->getJson('/api/payment-links/'.$id)->assertOk();
        $this->createLink($invoice)->assertForbidden();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertForbidden();
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertForbidden();
        $actor = $this->actor(Role::ADMIN);
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, function () {
            $this->configuration(['credentials' => ['api_key' => 'hp_test_foreign_secret']]);

            return Invoice::factory()->issued()->create();
        });
        $this->createLink($foreign)->assertNotFound();
        $this->getJson($this->url($foreign))->assertNotFound();
        $link = PaymentLink::findOrFail($id);
        $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/payment-links/'.$id)->assertForbidden();
        $this->assertCount(1, $this->provider->created);
        $this->expectException(AuthorizationException::class);
        app(TenantContext::class)->run($tenant, fn () => app(PaymentLinkWorkflow::class)->cancel($link, [], $actor));
    }

    public function test_explicit_permissions_grant_only_the_intended_payment_link_actions(): void
    {
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $this->actor(null, 'payment_links.create');
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $this->getJson('/api/payment-links/'.$id)->assertForbidden();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertForbidden();
        $this->actor(null, 'payment_links.view');
        $this->getJson('/api/payment-links/'.$id)->assertOk();
        $this->getJson($this->url($invoice))->assertOk();
        $this->createLink($invoice)->assertForbidden();
    }

    public function test_each_clinic_uses_its_own_merchant_and_hides_foreign_link_routes_from_members(): void
    {
        $actor = $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $id = $this->createLink($invoice)->assertCreated()->json('data.payment_link.id');
        $tenant = Tenant::factory()->create();
        $actor->tenants()->attach($tenant->id);
        $foreign = app(TenantContext::class)->run($tenant, function () use ($actor) {
            $role = Role::create(['slug' => Role::BILLING, 'name' => 'Billing']);
            $actor->roles()->attach($role);
            $this->configuration(['credentials' => ['api_key' => 'hp_test_foreign_secret']]);

            return Invoice::factory()->issued()->create();
        });
        $this->withHeader('X-Tenant-Id', $tenant->id);
        $this->getJson('/api/payment-links/'.$id)->assertNotFound();
        $this->postJson('/api/payment-links/'.$id.'/cancel')->assertNotFound();
        $this->postJson('/api/payment-links/'.$id.'/refresh')->assertNotFound();
        $this->createLink($foreign)->assertCreated();
        $this->assertSame('hp_test_clinic_secret', $this->provider->created[0]['credentials']['api_key']);
        $this->assertSame('hp_test_foreign_secret', $this->provider->created[1]['credentials']['api_key']);
        $this->withHeader('X-Tenant-Id', $invoice->tenant_id);
        $this->getJson($this->url($invoice))->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_provider_amount_or_currency_mismatch_is_quarantined_without_publishing_a_checkout(): void
    {
        $this->actor();
        $this->configuration();
        foreach ([['amountOverride', 1], ['currencyOverride', 'EUR']] as [$field, $value]) {
            $this->provider->amountOverride = null;
            $this->provider->currencyOverride = null;
            $this->provider->{$field} = $value;
            $invoice = Invoice::factory()->issued()->create();
            $this->createLink($invoice)->assertStatus(502)->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');
            $this->assertDatabaseHas('payment_links', ['invoice_id' => $invoice->id, 'status' => 'unknown', 'checkout_url' => null]);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_configuration_command_cannot_switch_merchants_while_a_checkout_is_outstanding(): void
    {
        $this->actor();
        $configuration = $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $this->createLink($invoice)->assertCreated();
        try {
            $this->artisan('billing:configure-provider', [
                'tenant' => $invoice->tenant_id, 'provider' => 'handypay',
                '--success-url' => 'https://clinic.example.test/complete', '--cancel-url' => 'https://clinic.example.test/cancel',
            ])->expectsQuestion('Merchant API key', 'hp_live_different_merchant')
                ->expectsQuestion('Webhook signing secret', 'sandbox_replacement_secret')->run();
            $this->fail('Expected outstanding checkout to prevent configuration changes.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('provider', $error->errors());
        }
        $this->assertSame(['api_key' => 'hp_test_clinic_secret'], $configuration->refresh()->credentials);
    }

    public function test_audit_failure_keeps_the_reserved_attempt_so_provider_is_never_retried(): void
    {
        $actor = $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $workflow = new PaymentLinkWorkflow(app(\App\Billing\Providers\PaymentProviderRegistry::class), $audit, app(\App\Services\PaymentSettlement::class));
        try {
            $workflow->create($invoice, ['idempotency_key' => 'link'], $actor);
            $this->fail('Expected audit failure.');
        } catch (ProviderException $e) {
            $this->assertSame('reconciliation_required', $e->reason);
            $this->assertNull($e->getPrevious());
        }
        $this->assertDatabaseHas('payment_links', ['invoice_id' => $invoice->id, 'status' => 'creating', 'external_reference' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->createLink($invoice)->assertStatus(409);
        $this->assertCount(1, $this->provider->created);
    }

    public function test_database_rejects_cross_tenant_invoice_relationships(): void
    {
        $this->actor();
        $this->configuration();
        $invoice = Invoice::factory()->issued()->create();
        $this->createLink($invoice)->assertCreated();
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Invoice::factory()->issued()->create());
        $this->expectException(QueryException::class);
        DB::table('payment_links')->update(['invoice_id' => $foreign->id]);
    }

    private function configuration(array $extra = []): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::create([
            'provider' => 'handypay', 'credentials' => ['api_key' => 'hp_test_clinic_secret'],
            'success_url' => 'https://clinic.example.test/complete', 'cancel_url' => 'https://clinic.example.test/cancel',
            'enabled' => true, ...$extra,
        ]);
    }

    private function actor(?string $slug = Role::BILLING, ?string $permission = null): User
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $slug ?? 'custom-'.$permission], ['name' => $slug ?? 'Custom']);
        if ($permission) {
            [$resource, $action] = explode('.', $permission, 2);
            $grant = Permission::firstOrCreate(['slug' => $permission], ['name' => $permission, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->syncWithoutDetaching([$grant->id]);
        }
        $actor->roles()->attach($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function createLink(Invoice $invoice, string $key = 'link')
    {
        return $this->postJson($this->url($invoice), ['idempotency_key' => $key]);
    }

    private function url(Invoice $invoice): string
    {
        return '/api/invoices/'.$invoice->id.'/payment-links';
    }
}
