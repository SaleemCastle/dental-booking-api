<?php

namespace Tests\Feature;

use App\Models\Patient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BillingMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_upgrade_preserves_legacy_ids_money_payments_and_snapshots_and_refuses_populated_rollback(): void
    {
        $migration = $this->migration();
        $migration->down();
        config(['billing.legacy_currency' => 'COP']);
        [$invoiceId, $paymentId] = $this->legacyInvoice();
        $before = (array) DB::table('payments')->find($paymentId);
        $migration->up();
        $invoice = DB::table('invoices')->find($invoiceId);
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertSame('partial', $invoice->legacy_status);
        $this->assertSame('COP', $invoice->currency);
        $this->assertEquals(100, $invoice->amount_due);
        $this->assertEquals(25, $invoice->amount_paid);
        $payment = (array) DB::table('payments')->find($paymentId);
        foreach ($before as $key => $value) {
            $this->assertEquals($value, $payment[$key], $key);
        }
        $this->assertSame('COP', $payment['currency']);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoiceId, 'description' => 'Legacy invoice total', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        try {
            $migration->down();
            $this->fail('Rollback should preserve billing history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('billing history', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('invoice_items'));
        $this->assertDatabaseHas('payments', ['id' => $paymentId]);
    }

    public function test_legacy_currency_must_be_explicit_and_mismatched_balances_fail_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        [$invoiceId] = $this->legacyInvoice();
        config(['billing.legacy_currency' => null]);
        try {
            $migration->up();
            $this->fail('Legacy currency must not be guessed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BILLING_LEGACY_CURRENCY', $e->getMessage());
        }
        config(['billing.legacy_currency' => 'USD']);
        DB::table('invoices')->where('id', $invoiceId)->update(['amount_paid' => 30]);
        try {
            $migration->up();
            $this->fail('Legacy balances must be reconciled.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reconcile legacy invoice', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('invoices', 'legacy_status'));
        $this->assertFalse(Schema::hasTable('invoice_items'));
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'amount_paid' => 30]);
    }

    public function test_empty_schema_can_round_trip_without_removing_tenant_guards(): void
    {
        $migration = $this->migration();
        $migration->down();
        $migration->up();
        $this->assertTrue(Schema::hasTable('invoice_items'));
        $patient = Patient::factory()->create();
        try {
            DB::table('invoices')->insert(['patient_id' => $patient->id, 'tenant_id' => 999999, 'invoice_number' => 'FOREIGN', 'currency' => 'USD']);
            $this->fail('Tenant guard must survive schema changes.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertMatchesRegularExpression('/Tenant integrity violation|FOREIGN KEY/', $e->getMessage());
        }
    }

    private function migration(): object
    {
        // Roll back dependent settlement schema before exercising the older billing migration.
        (require database_path('migrations/2026_10_28_000003_create_clinic_communications.php'))->down();
        (require database_path('migrations/2026_10_28_000002_add_payment_reconciliation.php'))->down();

        return require database_path('migrations/2026_10_27_000001_build_billing_foundation.php');
    }

    private function legacyInvoice(): array
    {
        $patient = Patient::factory()->create();
        $invoiceId = DB::table('invoices')->insertGetId([
            'patient_id' => $patient->id, 'tenant_id' => $patient->tenant_id, 'invoice_number' => 'LEGACY',
            'amount_due' => 100, 'amount_paid' => 25, 'status' => 'partial', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = DB::table('payments')->insertGetId([
            'tenant_id' => $patient->tenant_id, 'invoice_id' => $invoiceId, 'amount' => 25,
            'status' => 'paid', 'payment_method' => 'cash', 'reference' => 'Old receipt',
            'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$invoiceId, $paymentId];
    }
}
