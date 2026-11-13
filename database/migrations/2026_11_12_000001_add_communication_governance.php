<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = ['internal_view', 'patient_view', 'settings_manage', 'history_view', 'assign', 'escalate', 'supervise', 'archive', 'redact', 'export'];

    public function up(): void
    {
        Schema::table('messaging_user_permissions', function (Blueprint $table) {
            foreach ($this->permissions as $permission) {
                $table->boolean($permission)->nullable();
            }
        });
        Schema::create('conversation_access_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('participant_id')->unique()->constrained('conversation_participants')->restrictOnDelete();
            $table->boolean('active');
            $table->timestamps();
        });
        Schema::create('conversation_thread_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->unique()->constrained('conversation_threads')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('escalated_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->boolean('legal_hold')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamps();
        });
        Schema::create('communication_governance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('retention_days')->nullable();
            $table->unsignedInteger('archive_after_days')->nullable();
            $table->boolean('escalation_enabled')->default(false);
            $table->text('policy_reference');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->unique(['tenant_id', 'version'], 'communication_policy_version');
        });
        Schema::create('conversation_governance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('thread_id')->constrained('conversation_threads')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('conversation_messages')->restrictOnDelete();
            $table->string('action', 30);
            $table->text('reason');
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'thread_id', 'id'], 'conversation_governance_history');
        });
        DB::table('conversation_threads')->orderBy('id')->eachById(function ($thread) {
            DB::table('conversation_thread_states')->insert([
                'tenant_id' => $thread->tenant_id, 'thread_id' => $thread->id,
                'assigned_to' => $thread->patient_id === null ? null : $thread->created_by,
                'status' => 'open', 'legal_hold' => false,
                'last_activity_at' => DB::table('conversation_messages')->where('thread_id', $thread->id)->max('created_at') ?? $thread->created_at,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        });
        $relationships = [
            'conversation_access_states' => 'NOT EXISTS (SELECT 1 FROM conversation_participants WHERE id = NEW.participant_id AND tenant_id = NEW.tenant_id AND user_id IS NOT NULL)',
            'conversation_thread_states' => "NOT EXISTS (SELECT 1 FROM conversation_threads WHERE id = NEW.thread_id AND tenant_id = NEW.tenant_id) OR NEW.status NOT IN ('open','escalated','resolved','archived') OR (NEW.assigned_to IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_participants WHERE thread_id = NEW.thread_id AND tenant_id = NEW.tenant_id AND user_id = NEW.assigned_to)) OR (NEW.escalated_to IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_participants WHERE thread_id = NEW.thread_id AND tenant_id = NEW.tenant_id AND user_id = NEW.escalated_to))",
            'communication_governance_policies' => 'NOT EXISTS (SELECT 1 FROM tenant_user WHERE user_id = NEW.recorded_by AND tenant_id = NEW.tenant_id) OR NEW.version < 1',
            'conversation_governance_events' => 'NOT EXISTS (SELECT 1 FROM conversation_threads WHERE id = NEW.thread_id AND tenant_id = NEW.tenant_id) OR (NEW.actor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE user_id = NEW.actor_id AND tenant_id = NEW.tenant_id)) OR (NEW.subject_user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_participants WHERE thread_id = NEW.thread_id AND tenant_id = NEW.tenant_id AND user_id = NEW.subject_user_id)) OR (NEW.message_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM conversation_messages WHERE id = NEW.message_id AND thread_id = NEW.thread_id AND tenant_id = NEW.tenant_id))',
        ];
        foreach ($relationships as $table => $condition) {
            foreach (['insert', 'update'] as $event) {
                MigrationGuard::create($table.'_'.$event, $table, $event, $condition, 'Invalid communication governance relationship');
            }
        }
        foreach (['communication_governance_policies', 'conversation_governance_events'] as $table) {
            foreach (['update', 'delete'] as $event) {
                MigrationGuard::create($table.'_immutable_'.$event, $table, $event, '1 = 1', 'Communication governance history is append-only');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('conversation_governance_events')->exists() || DB::table('communication_governance_policies')->exists()) {
            throw new RuntimeException('Export and back up communication governance evidence before rollback.');
        }
        foreach (['conversation_governance_events', 'communication_governance_policies', 'conversation_thread_states', 'conversation_access_states'] as $table) {
            foreach (['insert', 'update', 'immutable_update', 'immutable_delete'] as $suffix) {
                MigrationGuard::drop($table.'_'.$suffix, $table);
            }
            Schema::drop($table);
        }
        Schema::table('messaging_user_permissions', fn (Blueprint $table) => $table->dropColumn($this->permissions));
    }
};
