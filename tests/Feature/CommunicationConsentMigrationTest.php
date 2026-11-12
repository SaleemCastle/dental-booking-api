<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunicationConsentMigrationTest extends TestCase
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

    public function test_legacy_evidence_is_preserved_without_inventing_acknowledged_policy_version(): void
    {
        $migration = require database_path('migrations/2026_11_11_000002_create_communication_consent_history.php');
        $migration->down();
        $patient = Patient::factory()->create();
        $actor = User::factory()->create();
        $legacyId = DB::table('patient_messaging_preferences')->insertGetId([
            'tenant_id' => Tenant::where('slug', Tenant::DEFAULT_SLUG)->value('id'), 'patient_id' => $patient->id,
            'channel' => 'in_app', 'use_case' => 'care', 'allowed' => true,
            'evidence' => 'Original signed consent evidence', 'recorded_by' => $actor->id,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-02 11:00:00',
        ]);
        $migration->up();
        $this->assertDatabaseHas('communication_consents', ['legacy_preference_id' => $legacyId,
            'purpose' => 'clinical', 'scope' => 'follow_up', 'status' => 'granted', 'version' => 1,
            'text_version' => 'legacy_unversioned', 'acknowledged_text' => null, 'evidence' => 'Original signed consent evidence',
            'captured_at' => '2026-11-02 11:00:00']);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
