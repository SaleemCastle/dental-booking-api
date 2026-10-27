<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Billing supports MySQL and SQLite.');
        }
        $legacyCurrency = config('billing.legacy_currency');
        if (DB::table('invoices')->exists() && (! is_string($legacyCurrency) || ! preg_match('/\A[A-Z]{3}\z/', $legacyCurrency))) {
            throw new RuntimeException('Set BILLING_LEGACY_CURRENCY to the verified currency of existing invoices before migrating.');
        }
        // Never invent payments or silently convert refunded/inconsistent legacy records.
        foreach (DB::table('invoices')->orderBy('id')->cursor() as $invoice) {
            $payments = DB::table('payments')->where('invoice_id', $invoice->id)->get();
            $paid = $payments->sum(fn ($payment) => $this->minor($payment->amount));
            if ($invoice->status === 'refunded' || $payments->contains(fn ($payment) => $payment->status !== 'paid' || $payment->amount <= 0)
                || $invoice->amount_due < 0 || $invoice->amount_paid < 0 || $invoice->amount_paid > $invoice->amount_due
                || $paid !== $this->minor($invoice->amount_paid)) {
                throw new RuntimeException('Reconcile legacy invoice '.$invoice->id.' and its payment ledger before migrating; no data was changed.');
            }
        }

        // Retain the original enum for recovery without rebuilding tables or losing tenant triggers.
        Schema::table('invoices', fn (Blueprint $table) => $table->renameColumn('status', 'legacy_status'));
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('status', 30)->default('draft')->index();
            $table->string('currency', 3)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable();
            $table->string('receipt_number')->nullable();
            $key = $table->string('idempotency_key', 100)->nullable();
            if (DB::getDriverName() === 'mysql') {
                $key->charset('ascii')->collation('ascii_bin');
            }
            // Lead with the key so MySQL retains its invoice FK support index.
            $table->unique(['idempotency_key', 'invoice_id']);
        });
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id', 'id']);
        });
        foreach (DB::table('invoices')->orderBy('id')->cursor() as $invoice) {
            $status = $this->minor($invoice->amount_paid) >= $this->minor($invoice->amount_due) ? 'paid' : ($this->minor($invoice->amount_paid) > 0 ? 'partially_paid' : 'issued');
            DB::table('invoices')->where('id', $invoice->id)->update([
                'status' => $status, 'currency' => $legacyCurrency, 'issued_at' => $invoice->issued_at ?? $invoice->created_at,
            ]);
            DB::table('invoice_items')->insert([
                'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id,
                'description' => 'Legacy invoice total', 'quantity' => 1, 'unit_price' => $invoice->amount_due,
                'line_total' => $invoice->amount_due, 'created_at' => $invoice->created_at, 'updated_at' => $invoice->updated_at,
            ]);
            DB::table('payments')->where('invoice_id', $invoice->id)->update(['currency' => $legacyCurrency]);
        }
        if (DB::getDriverName() === 'mysql') {
            // MySQL cascades bypass child delete triggers. Restrict the parent FK as well.
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['patient_id']);
                $table->foreign('patient_id')->references('id')->on('patient')->restrictOnDelete();
            });
        }
        $this->guard('patient', 'delete', 'EXISTS (SELECT 1 FROM invoices WHERE patient_id = OLD.id)');
        foreach (['invoices', 'payments', 'invoice_items'] as $table) {
            $this->guard($table, 'delete', '1 = 1');
        }
        foreach (['payments', 'invoice_items'] as $table) {
            $this->guard($table, 'update', '1 = 1');
        }
        $this->guard('invoice_items', 'insert', implode(' OR ', [
            'NEW.tenant_id IS NULL',
            "NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND tenant_id = NEW.tenant_id AND status = 'draft')",
            'NEW.treatment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM treatments WHERE id = NEW.treatment_id AND tenant_id = NEW.tenant_id)',
            'NEW.treatment_plan_item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM treatment_plan_items i JOIN treatment_plans p ON p.id = i.treatment_plan_id JOIN invoices v ON v.id = NEW.invoice_id WHERE i.id = NEW.treatment_plan_item_id AND i.tenant_id = NEW.tenant_id AND p.patient_id = v.patient_id AND i.treatment_id = NEW.treatment_id)',
            'NEW.quantity < 1 OR NEW.quantity > 10000 OR NEW.unit_price < 0 OR NEW.line_total < 0',
            'ROUND(NEW.line_total * 100) <> ROUND(NEW.unit_price * 100) * NEW.quantity',
        ]));
        $this->guard('payments', 'insert', implode(' OR ', [
            "NEW.status <> 'paid' OR NEW.amount <= 0 OR NEW.currency IS NULL OR NEW.paid_at IS NULL",
            'NEW.idempotency_key IS NULL',
            "NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND tenant_id = NEW.tenant_id AND currency = NEW.currency AND status IN ('issued', 'partially_paid') AND ROUND(NEW.amount * 100) <= ROUND(amount_due * 100) - COALESCE((SELECT SUM(ROUND(amount * 100)) FROM payments WHERE invoice_id = NEW.invoice_id), 0))",
        ]));
        $this->guard('invoices', 'insert', "NEW.status <> 'draft' OR NEW.currency IS NULL OR LENGTH(NEW.currency) <> 3 OR NEW.amount_due < 0 OR NEW.amount_paid <> 0 OR NEW.issued_at IS NOT NULL OR NEW.voided_at IS NOT NULL");
        $this->guard('invoices', 'update', implode(' OR ', [
            'NEW.id <> OLD.id OR NEW.patient_id <> OLD.patient_id OR NEW.amount_due <> OLD.amount_due OR NEW.currency <> OLD.currency OR NEW.currency IS NULL',
            'NEW.appointment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM appointment WHERE id = NEW.appointment_id AND patient_id = NEW.patient_id)',
            "NEW.status NOT IN ('draft', 'issued', 'partially_paid', 'paid', 'void')",
            "OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'issued', 'paid', 'void')",
            "OLD.status = 'issued' AND NEW.status NOT IN ('issued', 'partially_paid', 'paid', 'void')",
            "OLD.status = 'partially_paid' AND NEW.status NOT IN ('partially_paid', 'paid')",
            "OLD.status IN ('paid', 'void') AND NEW.status <> OLD.status",
            "OLD.status <> 'draft' AND (NEW.invoice_number <> OLD.invoice_number OR COALESCE(NEW.due_date, '') <> COALESCE(OLD.due_date, ''))",
            "OLD.status <> 'draft' AND COALESCE(NEW.issued_at, '') <> COALESCE(OLD.issued_at, '')",
            "OLD.status = 'void' AND (COALESCE(NEW.void_reason, '') <> COALESCE(OLD.void_reason, '') OR COALESCE(NEW.voided_at, '') <> COALESCE(OLD.voided_at, ''))",
            "NEW.status <> 'void' AND (NEW.voided_at IS NOT NULL OR NEW.void_reason IS NOT NULL)",
            "NEW.status = 'draft' AND (NEW.amount_paid <> 0 OR NEW.issued_at IS NOT NULL)",
            'ROUND(NEW.amount_paid * 100) <> COALESCE((SELECT SUM(ROUND(amount * 100)) FROM payments WHERE invoice_id = NEW.id), 0)',
            "NEW.status IN ('issued', 'partially_paid', 'paid') AND (NEW.issued_at IS NULL OR ROUND(NEW.amount_due * 100) <> COALESCE((SELECT SUM(ROUND(line_total * 100)) FROM invoice_items WHERE invoice_id = NEW.id), -1))",
            "NEW.status = 'issued' AND (NEW.amount_paid <> 0 OR NEW.amount_due <= 0)",
            "NEW.status = 'partially_paid' AND (NEW.amount_paid <= 0 OR NEW.amount_paid >= NEW.amount_due)",
            "NEW.status = 'paid' AND NEW.amount_paid <> NEW.amount_due",
            "NEW.status = 'void' AND (NEW.amount_paid <> 0 OR NEW.voided_at IS NULL OR NEW.void_reason IS NULL)",
        ]));
    }

    public function down(): void
    {
        // Even drafts are financial history; rollback must never discard it.
        if (DB::table('invoices')->exists() || DB::table('payments')->exists() || DB::table('invoice_items')->exists()) {
            throw new RuntimeException('Back up and explicitly recover billing history before removing its schema.');
        }
        foreach (['patient' => ['delete'], 'invoices' => ['insert', 'update', 'delete'], 'payments' => ['insert', 'update', 'delete'], 'invoice_items' => ['insert', 'update', 'delete']] as $table => $events) {
            foreach ($events as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS billing_{$table}_{$event}");
            }
        }
        Schema::dropIfExists('invoice_items');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key', 'invoice_id']);
            $table->dropColumn(['currency', 'receipt_number', 'idempotency_key']);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'currency', 'voided_at', 'void_reason']);
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->renameColumn('legacy_status', 'status'));
        if (DB::getDriverName() === 'mysql') {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['patient_id']);
                $table->foreign('patient_id')->references('id')->on('patient')->cascadeOnDelete();
            });
        }
    }

    private function guard(string $table, string $event, string $condition): void
    {
        $name = 'billing_'.$table.'_'.$event;
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Billing integrity violation: immutable financial history'); END");
        } else {
            DB::unprepared("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing integrity violation: immutable financial history'; END IF; END");
        }
    }

    private function minor(mixed $amount): int
    {
        if (! preg_match('/\A([0-9]{1,8})(?:\.([0-9]{1,2}))?\z/', (string) $amount, $parts)) {
            throw new RuntimeException('Reconcile legacy invoice money values before migrating; no data was changed.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
};
