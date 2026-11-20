<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\ConversationThread;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class CommunicationGovernanceMigrationTest extends TestCase
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

    public function test_upgrade_preserves_existing_authorship_and_backfills_assignment_and_activity(): void
    {
        $migration = require database_path('migrations/2026_11_12_000001_add_communication_governance.php');
        $migration->down();
        $actor = User::factory()->create();
        $patient = Patient::factory()->create();
        $thread = ConversationThread::create(['patient_id' => $patient->id, 'created_by' => $actor->id, 'subject' => 'Existing private discussion', 'use_case' => 'care', 'created_at' => '2026-11-01 09:00:00']);
        $sender = $thread->participants()->create(['user_id' => $actor->id]);
        $recipient = $thread->participants()->create(['patient_id' => $patient->id]);
        $message = ConversationMessage::create(['thread_id' => $thread->id, 'sender_id' => $sender->id, 'actor_id' => $actor->id, 'recipient_ids' => [$recipient->id],
            'body' => 'Existing protected content', 'kind' => 'message', 'channel' => 'in_app', 'delivery_state' => 'awaiting_portal',
            'idempotency_key' => 'legacy-send', 'payload_hash' => str_repeat('a', 64), 'audit_metadata' => [], 'created_at' => '2026-11-02 10:00:00']);
        $migration->up();
        $this->assertDatabaseHas('conversation_thread_states', ['thread_id' => $thread->id, 'assigned_to' => $actor->id, 'status' => 'open', 'last_activity_at' => '2026-11-02 10:00:00']);
        $this->assertDatabaseHas('conversation_messages', ['id' => $message->id, 'actor_id' => $actor->id, 'body' => 'Existing protected content']);
        $this->assertDatabaseCount('conversation_governance_events', 0);
        $this->assertDatabaseCount('communication_governance_policies', 0);
    }
}
