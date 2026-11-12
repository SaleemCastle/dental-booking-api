<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patient')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject', 200);
            $table->string('use_case', 30);
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id']);
        });
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->constrained('conversation_threads')->restrictOnDelete();
            // Patient identity is independent of a future portal login.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patient')->restrictOnDelete();
            $table->unique(['thread_id', 'user_id']);
            $table->unique(['thread_id', 'patient_id']);
        });
        Schema::create('messaging_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            foreach (['internal_send', 'internal_receive', 'patient_send'] as $permission) {
                $table->boolean($permission)->nullable();
            }
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        Schema::create('patient_messaging_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('channel', 20)->default('in_app');
            $table->string('use_case', 30);
            $table->boolean('allowed');
            $table->text('evidence');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'patient_id', 'channel', 'use_case'], 'patient_messaging_preference_unique');
        });
        Schema::create('message_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->constrained('conversation_threads')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->constrained('conversation_threads')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('conversation_participants')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('recipient_ids');
            $table->text('body')->nullable();
            $table->string('kind', 20)->default('message');
            $table->foreignId('supersedes_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('channel', 20)->default('in_app');
            $table->string('delivery_state', 30);
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->json('audit_metadata');
            $table->timestamp('created_at');
            $table->unique(['tenant_id', 'actor_id', 'idempotency_key'], 'conversation_send_unique');
            $table->index(['tenant_id', 'thread_id', 'id']);
        });

        $relationships = [
            'conversation_threads' => 'NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.created_by) OR (NEW.patient_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM patient WHERE tenant_id = NEW.tenant_id AND id = NEW.patient_id))',
            'conversation_participants' => 'NOT EXISTS (SELECT 1 FROM conversation_threads WHERE tenant_id = NEW.tenant_id AND id = NEW.thread_id) OR (NEW.user_id IS NULL AND NEW.patient_id IS NULL) OR (NEW.user_id IS NOT NULL AND NEW.patient_id IS NOT NULL) OR (NEW.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id)) OR (NEW.patient_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_threads WHERE tenant_id = NEW.tenant_id AND id = NEW.thread_id AND patient_id = NEW.patient_id))',
            'messaging_user_permissions' => 'NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.user_id)',
            'patient_messaging_preferences' => 'NOT EXISTS (SELECT 1 FROM patient WHERE tenant_id = NEW.tenant_id AND id = NEW.patient_id) OR NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.recorded_by)',
            'message_confirmations' => 'NOT EXISTS (SELECT 1 FROM conversation_participants WHERE tenant_id = NEW.tenant_id AND thread_id = NEW.thread_id AND user_id = NEW.user_id)',
            'conversation_messages' => 'NOT EXISTS (SELECT 1 FROM conversation_participants WHERE tenant_id = NEW.tenant_id AND thread_id = NEW.thread_id AND id = NEW.sender_id AND user_id = NEW.actor_id) OR (NEW.supersedes_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_messages WHERE tenant_id = NEW.tenant_id AND thread_id = NEW.thread_id AND id = NEW.supersedes_id))',
        ];
        foreach ($relationships as $table => $condition) {
            foreach (['insert', 'update'] as $event) {
                MigrationGuard::create($table.'_'.$event, $table, $event, $condition, 'Invalid messaging clinic relationship');
            }
        }
        foreach (['conversation_messages', 'conversation_participants', 'conversation_threads'] as $table) {
            foreach (['update', 'delete'] as $event) {
                MigrationGuard::create($table.'_immutable_'.$event, $table, $event, '1 = 1', 'Conversation history is append-only');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('conversation_threads')->exists()) {
            throw new RuntimeException('Export and back up conversation history before rollback.');
        }
        foreach (['conversation_messages', 'message_confirmations', 'patient_messaging_preferences', 'messaging_user_permissions', 'conversation_participants', 'conversation_threads'] as $table) {
            foreach (['insert', 'update', 'immutable_update', 'immutable_delete'] as $suffix) {
                MigrationGuard::drop($table.'_'.$suffix, $table);
            }
            Schema::drop($table);
        }
    }
};
