<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $relationships = [
        'patient_contacts' => ['patient_id' => 'patient'],
        'communication_configurations' => [],
        'clinic_notifications' => ['invoice_id' => 'invoices', 'payment_id' => 'payments', 'payment_link_id' => 'payment_links', 'configuration_id' => 'communication_configurations'],
        'notification_deliveries' => ['notification_id' => 'clinic_notifications', 'patient_contact_id' => 'patient_contacts'],
    ];

    public function up(): void
    {
        Schema::create('patient_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('channel', 20);
            $table->text('address');
            $table->string('role', 20);
            $table->json('authorized_events');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_method', 30)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id']);
        });
        Schema::create('communication_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->text('settings');
            $table->timestamps();
        });
        Schema::create('clinic_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('configuration_id')->constrained('communication_configurations')->restrictOnDelete();
            $table->unsignedInteger('configuration_revision');
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 50);
            $table->string('event_key', 100);
            $table->unique(['tenant_id', 'event_key']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('user_triggered');
            $table->string('trigger', 40);
            $table->text('variables');
            $table->string('selection_status', 30);
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('notification_id')->constrained('clinic_notifications')->restrictOnDelete();
            $table->foreignId('patient_contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('copy_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('recipient_kind', 20);
            $table->text('address');
            $table->string('recipient_hash', 64);
            $table->unique(['notification_id', 'channel', 'recipient_hash'], 'delivery_recipient_unique');
            $table->uuid('operation_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_reason', 60)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'available_at'], 'delivery_due_index');
        });
        // Match the clinical schema's raw-write tenant and immutable ownership guards.
        foreach ($this->relationships as $table => $relationships) {
            foreach (['insert', 'update'] as $event) {
                $checks = [];
                foreach ($relationships as $column => $parent) {
                    $checks[] = "(NEW.$column IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $parent WHERE id = NEW.$column AND tenant_id = NEW.tenant_id))";
                }
                if ($event === 'update') {
                    $checks[] = 'NEW.tenant_id <> OLD.tenant_id';
                }
                if ($checks === []) {
                    continue;
                }
                $condition = implode(' OR ', $checks);
                DB::unprepared(DB::getDriverName() === 'mysql'
                    ? "CREATE TRIGGER {$table}_clinic_{$event} BEFORE {$event} ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Communication tenant mismatch'; END IF; END"
                    : "CREATE TRIGGER {$table}_clinic_{$event} BEFORE {$event} ON $table FOR EACH ROW WHEN $condition BEGIN SELECT RAISE(ABORT, 'Communication tenant mismatch'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('clinic_notifications')->exists() || DB::table('patient_contacts')->exists() || DB::table('communication_configurations')->exists()) {
            throw new RuntimeException('Back up communication history before removing its schema.');
        }
        foreach (array_reverse(array_keys($this->relationships)) as $table) {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_clinic_{$event}");
            }
            Schema::dropIfExists($table);
        }
    }
};
