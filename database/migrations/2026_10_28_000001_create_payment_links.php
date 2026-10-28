<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->text('credentials');
            $table->text('success_url');
            $table->text('cancel_url');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('payment_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_provider_configuration_id');
            $table->foreign(['tenant_id', 'payment_provider_configuration_id'], 'payment_links_configuration_tenant_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configurations')->restrictOnDelete();
            $table->string('provider', 40);
            $table->uuid('operation_key')->unique();
            $key = $table->string('idempotency_key', 100);
            if (DB::getDriverName() === 'mysql') {
                $key->charset('ascii')->collation('ascii_bin');
            }
            $table->unique(['invoice_id', 'idempotency_key']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('external_reference')->nullable();
            $table->unique(['payment_provider_configuration_id', 'external_reference'], 'payment_links_external_reference_unique');
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 30)->default('creating');
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id', 'status']);
        });
        // Invoice IDs are globally unique; enforce ownership for raw SQL too.
        foreach (['insert', 'update'] as $event) {
            $condition = 'NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND tenant_id = NEW.tenant_id)';
            $sql = DB::getDriverName() === 'mysql'
                ? "CREATE TRIGGER payment_links_tenant_{$event} BEFORE {$event} ON payment_links FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment link tenant mismatch'; END IF; END"
                : "CREATE TRIGGER payment_links_tenant_{$event} BEFORE {$event} ON payment_links FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Payment link tenant mismatch'); END";
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        if (DB::table('payment_links')->exists()) {
            throw new RuntimeException('Reconcile and back up payment links before removing their schema.');
        }
        foreach (['insert', 'update'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS payment_links_tenant_{$event}");
        }
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('payment_provider_configurations');
    }
};
