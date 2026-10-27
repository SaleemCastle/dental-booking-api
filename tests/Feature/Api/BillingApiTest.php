<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BillingWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshots_and_exact_totals_survive_catalog_and_plan_changes(): void
    {
        $actor = $this->actor();
        $source = TreatmentPlanItem::factory()->create(['estimated_price' => '12.34']);
        $catalog = Treatment::factory()->create(['name' => 'Cleaning', 'price' => '0.10']);
        $invoice = $this->postJson('/api/invoices', $this->payload([
            'patient_id' => $source->treatmentPlan->patient_id,
            'items' => [
                ['treatment_plan_item_id' => $source->id, 'quantity' => 2],
                ['treatment_id' => $catalog->id, 'quantity' => 3],
                ['description' => 'Custom service', 'unit_price' => '0.20'],
            ],
        ]), ['X-Request-Id' => 'billing-contract'])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId'])
            ->assertJsonPath('requestId', 'billing-contract')->assertJsonPath('data.invoice.status', 'draft')
            ->assertJsonPath('data.invoice.amount_due', '25.18')->assertJsonPath('data.invoice.amount_paid', '0.00')
            ->assertJsonPath('data.invoice.balance', '25.18')->assertJsonPath('data.invoice.items.0.unit_price', '12.34')
            ->assertJsonPath('data.invoice.items.1.description', 'Cleaning')->json('data.invoice');
        $source->update(['estimated_price' => '99.00']);
        $catalog->update(['price' => '77.00', 'name' => 'Changed']);
        $this->getJson('/api/invoices/'.$invoice['id'])->assertOk()
            ->assertJsonPath('data.invoice.items.0.unit_price', '12.34')
            ->assertJsonPath('data.invoice.items.1.description', 'Cleaning')->assertJsonPath('data.invoice.amount_due', '25.18');
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'resource_type' => 'invoices', 'resource_id' => (string) $invoice['id'], 'actor_id' => $actor->id]);
        $this->assertStringNotContainsString('Custom service', AuditLog::all()->toJson());
    }

    public function test_partial_payments_exact_balance_receipts_and_idempotent_retries(): void
    {
        $this->actor();
        $invoice = $this->createInvoice('0.30');
        $this->pay($invoice, '0.10')->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->patchJson('/api/invoices/'.$invoice->id, ['status' => 'issued'])->assertOk()->assertJsonPath('data.invoice.status', 'issued');
        $first = $this->pay($invoice, '0.10', 'first')->assertCreated()->assertJsonPath('data.payment.receipt_number', 'receipt-first')->json('data.payment.id');
        $count = AuditLog::count();
        $this->pay($invoice, '0.10', 'first')->assertOk()->assertJsonPath('data.payment.id', $first);
        $this->pay($invoice, '0.11', 'first')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame($count, AuditLog::count());
        $this->getJson('/api/invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.invoice.status', 'partially_paid')
            ->assertJsonPath('data.invoice.amount_paid', '0.10')->assertJsonPath('data.invoice.balance', '0.20');
        $this->pay($invoice, '0.21', 'overpay')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($invoice, '0.20', 'second')->assertCreated();
        $this->getJson('/api/invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.amount_paid', '0.30')->assertJsonPath('data.invoice.balance', '0.00');
        $this->pay($invoice, '0.01', 'extra')->assertUnprocessable();
        $this->pay($invoice, '0.10', 'first')->assertOk();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recorded', 'resource_type' => 'payments', 'resource_id' => (string) $first]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'partially_paid', 'resource_type' => 'invoices']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'paid', 'resource_type' => 'invoices']);
        $this->getJson('/api/payments/'.$first)->assertOk()->assertJsonPath('data.payment.reference', 'ref-first');
    }

    public function test_lifecycle_draft_edits_issue_zero_total_void_and_terminal_protections(): void
    {
        $this->actor();
        $invoice = $this->createInvoice();
        $url = '/api/invoices/'.$invoice->id;
        $this->patchJson($url, ['invoice_number' => 'RENAMED', 'due_date' => '2026-12-01'])->assertOk();
        $count = AuditLog::count();
        $this->patchJson($url, [])->assertOk();
        $this->assertSame($count, AuditLog::count());
        foreach ([['status' => 'paid'], ['status' => 'partially_paid'], ['amount_due' => 1], ['items' => []], ['currency' => 'EUR']] as $data) {
            $this->patchJson($url, $data)->assertUnprocessable();
        }
        $this->patchJson($url, ['status' => 'issued'])->assertOk();
        $this->patchJson($url, ['invoice_number' => 'Changed'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'draft'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'void'])->assertUnprocessable()->assertJsonValidationErrors('void_reason');
        $this->patchJson($url, ['status' => 'void', 'void_reason' => 'Duplicate invoice'])->assertOk()
            ->assertJsonPath('data.invoice.balance', '0.00')->assertJsonPath('data.invoice.amount_due', '100.00')
            ->assertJsonPath('data.invoice.status', 'void');
        $this->patchJson($url, ['status' => 'issued'])->assertUnprocessable();
        $this->pay($invoice, '1')->assertUnprocessable();
        $zero = $this->createInvoice('0.00');
        $this->patchJson('/api/invoices/'.$zero->id, ['status' => 'issued'])->assertOk()->assertJsonPath('data.invoice.status', 'paid');
        $paid = $this->createInvoice();
        $this->patchJson('/api/invoices/'.$paid->id, ['status' => 'issued'])->assertOk();
        $this->pay($paid, '1')->assertCreated();
        $this->patchJson('/api/invoices/'.$paid->id, ['status' => 'void', 'void_reason' => 'Wrong'])->assertUnprocessable();
        $draft = $this->createInvoice();
        $this->patchJson('/api/invoices/'.$draft->id, ['status' => 'void', 'void_reason' => 'Cancelled'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'voided', 'resource_type' => 'invoices', 'resource_id' => (string) $draft->id]);
    }

    public function test_idempotency_keys_are_case_sensitive_and_retries_compare_receipt_and_timestamp(): void
    {
        $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        $data = [...$this->paymentPayload($invoice, '1.00', 'Receipt-A'), 'paid_at' => now()->subMinute()->toISOString()];
        $this->postJson('/api/payments', $data)->assertCreated();
        $this->postJson('/api/payments', $data)->assertOk();
        $this->postJson('/api/payments', [...$data, 'reference' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/payments', [...$data, 'paid_at' => now()->subHour()->toISOString()])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/payments', [...$data, 'idempotency_key' => 'receipt-a'])->assertCreated();
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('2.00', $invoice->refresh()->amount_paid);
    }

    public static function invalidPayments(): array
    {
        return [
            ['amount', 0], ['amount', -1], ['amount', '0.001'], ['amount', '1e1'], ['amount', '100000000.00'],
            ['amount', null], ['currency', 'EUR'], ['paid_at', 'tomorrow'], ['payment_method', 'gateway_specific'],
            ['card_number', '4111111111111111'], ['cvv', '123'], ['card', ['number' => '4111111111111111']],
            ['metadata', ['pan' => '4111111111111111']], ['status', 'paid'], ['tenant_id', 1], ['idempotency_key', ''],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function test_invalid_payments_never_change_the_ledger_or_audit(string $field, mixed $value): void
    {
        $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        $this->postJson('/api/payments', array_replace($this->paymentPayload($invoice), [$field => $value]))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('0.00', $invoice->refresh()->amount_paid);
    }

    public function test_invalid_invoice_sources_overflows_and_later_failures_are_atomic(): void
    {
        $this->actor();
        $inactive = Treatment::factory()->create(['active' => false]);
        $other = TreatmentPlanItem::factory()->create();
        $patient = Patient::factory()->create();
        foreach ([
            ['items' => []], ['items' => [['description' => 'Invalid', 'unit_price' => '0.001']]],
            ['items' => [['description' => 'Too large', 'unit_price' => '99999999.99', 'quantity' => 2]]],
            ['items' => [['description' => 'Valid', 'unit_price' => 1], ['treatment_id' => $inactive->id]]],
            ['items' => [['treatment_plan_item_id' => $other->id]]],
            ['items' => [['treatment_id' => $inactive->id, 'unit_price' => 1]]],
            ['appointment_id' => Appointment::factory()->create()->id],
            ['amount_due' => 1], ['status' => 'paid'], ['amount_paid' => 1], ['currency' => 'usd'],
        ] as $extra) {
            $this->postJson('/api/invoices', $this->payload(['patient_id' => $patient->id, ...$extra]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_authorization_permissions_pagination_and_tenant_isolation(): void
    {
        $this->getJson('/api/invoices')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->actor(Role::DENTIST);
        $this->getJson('/api/invoices')->assertForbidden();
        $this->postJson('/api/invoices', $this->payload())->assertForbidden();
        $this->actor();
        $invoice = $this->createInvoice();
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Invoice::factory()->issued()->withPayment(25)->create()->load('payments'));
        $this->getJson('/api/invoices/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/invoices/'.$foreign->id, ['status' => 'void', 'void_reason' => 'Bad'])->assertNotFound();
        $this->getJson('/api/payments/'.$foreign->payments->first()->id)->assertNotFound();
        $this->postJson('/api/payments', $this->paymentPayload($foreign))->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->postJson('/api/invoices', $this->payload(['patient_id' => $foreign->patient_id]))->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->getJson('/api/invoices?per_page=1&status=draft')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $invoice->id);
        $this->getJson('/api/invoices?per_page=101')->assertUnprocessable();
        $this->getJson('/api/payments?invoice_id='.$invoice->id)->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->actor(Role::AUDITOR);
        $this->getJson('/api/invoices/'.$invoice->id)->assertOk();
        $this->getJson('/api/payments')->assertOk();
        $this->patchJson('/api/invoices/'.$invoice->id, ['status' => 'issued'])->assertForbidden();
        $this->actor(null, 'invoices.view');
        $this->getJson('/api/invoices')->assertOk();
        $this->postJson('/api/invoices', $this->payload())->assertForbidden();
        $this->actor(null, 'payments.create');
        $this->postJson('/api/payments', $this->paymentPayload($invoice))->assertUnprocessable();
    }

    public function test_financial_records_cannot_be_deleted_or_rewritten_through_api_or_raw_sql(): void
    {
        $this->actor(Role::ADMIN);
        $invoice = Invoice::factory()->issued()->withPayment(25)->create();
        $payment = $invoice->payments()->firstOrFail();
        foreach ([['DELETE', '/api/invoices/'.$invoice->id], ['DELETE', '/api/payments/'.$payment->id], ['PATCH', '/api/payments/'.$payment->id]] as [$method, $url]) {
            $this->json($method, $url, [])->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
        }
        foreach ([
            fn () => DB::table('invoices')->where('id', $invoice->id)->delete(),
            fn () => DB::table('payments')->where('id', $payment->id)->delete(),
            fn () => DB::table('invoice_items')->where('invoice_id', $invoice->id)->delete(),
            fn () => DB::table('patient')->where('id', $invoice->patient_id)->delete(),
            fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => 99]),
            fn () => DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['unit_price' => 99]),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => 99]),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['amount_due' => 99]),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'draft']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected financial history guard.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Billing integrity violation', $e->getMessage());
            }
        }
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'amount_paid' => 25]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_archived_patients_allow_settlement_but_not_new_invoices(): void
    {
        $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        $invoice->patient->archive();
        $this->postJson('/api/invoices', $this->payload(['patient_id' => $invoice->patient_id]))->assertUnprocessable();
        $this->pay($invoice, '100')->assertCreated();
    }

    public function test_dashboard_authorizes_financial_totals_excludes_drafts_and_voids_and_separates_currencies(): void
    {
        $this->actor();
        Invoice::factory()->issued()->withPayment(25)->create();
        $draft = Invoice::factory()->create(['amount_due' => 999]);
        $this->patchJson('/api/invoices/'.$draft->id, ['status' => 'void', 'void_reason' => 'Cancelled'])->assertOk();
        Invoice::factory()->create(['amount_due' => 500]);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.unpaidInvoiceAmount', 75)
            ->assertJsonPath('data.collectionRate', 25)->assertJsonPath('data.billingTotals.0.currency', 'USD');
        Invoice::factory()->issued()->create(['currency' => 'COP', 'amount_due' => 10000]);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.unpaidInvoiceAmount', null)
            ->assertJsonPath('data.collectionRate', null)->assertJsonCount(2, 'data.billingTotals')
            ->assertJsonPath('data.billingTotals.0.currency', 'COP')->assertJsonPath('data.billingTotals.0.unpaidInvoiceAmount', 10000)
            ->assertJsonPath('data.billingTotals.1.unpaidInvoiceAmount', 75);
        $this->actor(Role::DENTIST);
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.unpaidInvoiceAmount', null)
            ->assertJsonPath('data.collectionRate', null)->assertJsonPath('data.billingTotals', null);
    }

    public function test_audit_failure_rolls_back_payment_balance_and_lifecycle(): void
    {
        $actor = $this->actor();
        $invoice = Invoice::factory()->issued()->create();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            (new BillingWorkflow($audit))->recordPayment($this->paymentPayload($invoice), $actor);
            $this->fail('Audit failure should abort payment.');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('0.00', $invoice->amount_paid);
    }

    public function test_workflow_authorizes_direct_callers(): void
    {
        $actor = $this->actor(Role::RECEPTIONIST);
        $this->expectException(AuthorizationException::class);
        app(BillingWorkflow::class)->create($this->payload(), $actor);
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'patient_id' => Patient::factory()->create()->id,
            'invoice_number' => fake()->unique()->uuid(), 'currency' => 'USD',
            'items' => [['description' => 'Service', 'unit_price' => '100.00']],
        ], $extra);
    }

    private function createInvoice(string $total = '100.00'): Invoice
    {
        $id = $this->postJson('/api/invoices', $this->payload(['items' => [['description' => 'Service', 'unit_price' => $total]]]))
            ->assertCreated()->json('data.invoice.id');

        return Invoice::findOrFail($id);
    }

    private function paymentPayload(Invoice $invoice, string $amount = '25.00', string $key = 'payment'): array
    {
        return ['invoice_id' => $invoice->id, 'amount' => $amount, 'currency' => 'USD', 'payment_method' => 'card',
            'reference' => 'ref-'.$key, 'receipt_number' => 'receipt-'.$key, 'idempotency_key' => $key];
    }

    private function pay(Invoice $invoice, string $amount, string $key = 'payment')
    {
        return $this->postJson('/api/payments', $this->paymentPayload($invoice, $amount, $key));
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
}
