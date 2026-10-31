<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            // Keep old merchant credentials for delayed deliveries; only the enabled revision creates checkouts.
            $table->dropUnique(['tenant_id']);
            $table->uuid('webhook_endpoint')->nullable()->unique();
            $table->timestamp('sandbox_verified_at')->nullable();
        });
        foreach (DB::table('payment_provider_configurations')->pluck('id') as $id) {
            DB::table('payment_provider_configurations')->where('id', $id)->update(['webhook_endpoint' => (string) Str::uuid()]);
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('payment_link_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_provider_configuration_id')->nullable()->constrained()->restrictOnDelete();
            $reference = $table->string('provider_transaction_reference')->nullable();
            if (DB::getDriverName() === 'mysql') {
                $reference->charset('ascii')->collation('ascii_bin');
            }
            $table->unique(['payment_provider_configuration_id', 'provider_transaction_reference'], 'payments_provider_transaction_unique');
        });
        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_provider_configuration_id');
            $table->foreign(['tenant_id', 'payment_provider_configuration_id'], 'payment_events_configuration_tenant_fk')
                ->references(['tenant_id', 'id'])->on('payment_provider_configurations')->restrictOnDelete();
            $eventId = $table->string('event_id');
            if (DB::getDriverName() === 'mysql') {
                $eventId->charset('ascii')->collation('ascii_bin');
            }
            $table->string('event_type', 100);
            $table->string('payload_hash', 64);
            $table->string('provider_transaction_reference')->nullable();
            $table->foreignId('payment_link_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('outcome', 40)->default('received');
            $table->timestamps();
            $table->unique(['payment_provider_configuration_id', 'event_id'], 'payment_events_delivery_unique');
            $table->index(['tenant_id', 'outcome']);
        });
        $this->guard('payments_provider_insert', 'payments', implode(' OR ', [
            '(NEW.payment_link_id IS NULL AND (NEW.payment_provider_configuration_id IS NOT NULL OR NEW.provider_transaction_reference IS NOT NULL))',
            '(NEW.payment_link_id IS NOT NULL AND (NEW.payment_provider_configuration_id IS NULL OR NEW.provider_transaction_reference IS NULL))',
            '(NEW.payment_link_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_links l WHERE l.id = NEW.payment_link_id AND l.invoice_id = NEW.invoice_id AND l.tenant_id = NEW.tenant_id AND l.payment_provider_configuration_id = NEW.payment_provider_configuration_id AND l.currency = NEW.currency AND ROUND(l.amount * 100) = ROUND(NEW.amount * 100)))',
        ]));
        $this->guard('payment_events_provider_insert', 'payment_provider_events', 'NEW.payment_link_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_links l WHERE l.id = NEW.payment_link_id AND l.tenant_id = NEW.tenant_id AND l.payment_provider_configuration_id = NEW.payment_provider_configuration_id)');
        $this->guard('payment_events_provider_update', 'payment_provider_events', 'NEW.payment_link_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_links l WHERE l.id = NEW.payment_link_id AND l.tenant_id = NEW.tenant_id AND l.payment_provider_configuration_id = NEW.payment_provider_configuration_id)', 'update');
    }

    private function guard(string $name, string $table, string $condition, string $event = 'insert'): void
    {
        MigrationGuard::create($name, $table, $event, $condition, 'Provider relationship mismatch');
    }

    public function down(): void
    {
        if (DB::table('payment_provider_events')->exists() || DB::table('payments')->whereNotNull('payment_link_id')->exists()
            || DB::table('payment_provider_configurations')->select('tenant_id')->groupBy('tenant_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Back up and reconcile provider history before removing settlement schema.');
        }
        foreach (['payments_provider_insert', 'payment_events_provider_insert', 'payment_events_provider_update'] as $trigger) {
            MigrationGuard::drop($trigger, $trigger === 'payments_provider_insert' ? 'payments' : 'payment_provider_events');
        }
        Schema::dropIfExists('payment_provider_events');
        Schema::table('payments', function (Blueprint $table): void {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['payment_link_id']);
                $table->dropForeign(['payment_provider_configuration_id']);
            }
            $table->dropUnique('payments_provider_transaction_unique');
            $table->dropUnique(['payment_link_id']);
            $table->dropColumn(['payment_link_id', 'payment_provider_configuration_id', 'provider_transaction_reference']);
        });
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->dropUnique(['webhook_endpoint']);
            $table->dropColumn(['webhook_endpoint', 'sandbox_verified_at']);
            $table->unique('tenant_id');
        });
    }
};
