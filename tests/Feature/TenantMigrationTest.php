<?php

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_one_records_ids_links_and_history_survive_migration(): void
    {
        $this->seed(DatabaseSeeder::class);
        $patient = Patient::firstOrFail();
        $patient->archive(User::firstOrFail());
        Treatment::firstOrFail()->update(['active' => false]);
        Task::factory()->create();
        Message::create(['sender_id' => $patient->id, 'receiver_id' => $patient->id, 'message_text' => 'Historical message']);
        Referral::create(['patient_id' => $patient->id, 'dentist_id' => Dentist::firstOrFail()->id, 'referred_to_email' => 'historical@example.test', 'referral_date' => '2026-11-01', 'referral_notes' => 'Historical referral']);
        app(AuditLogService::class)->record('created', 'patients', $patient->id, User::firstOrFail()->id);
        $note = ClinicalNote::firstOrFail();
        $note->delete();
        $migration = require database_path('migrations/2026_10_21_000001_add_tenant_foundation.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('tenants'));
        $tables = ['users', 'roles', 'role_user', 'permissions', 'permission_role', 'patient', 'dentist', 'treatments', 'rooms', 'chairs', 'appointment', 'invoices', 'payments', 'messages', 'referral', 'clinical_notes', 'tasks', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $migration->up();

        $tenantId = DB::table('tenants')->where('slug', Tenant::DEFAULT_SLUG)->value('id');
        foreach ($before as $table => $records) {
            $after = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                $values = (array) $row;
                unset($values['tenant_id']);

                return $values;
            })->all();
            $this->assertEquals($records, $after, $table.' data changed during tenant migration');
            if (Schema::hasColumn($table, 'tenant_id')) {
                $this->assertSame(0, DB::table($table)->where('tenant_id', '!=', $tenantId)->orWhereNull('tenant_id')->count());
            }
        }
        $this->assertSame(DB::table('users')->count(), DB::table('tenant_user')->where('tenant_id', $tenantId)->count());
        $this->assertNotNull(DB::table('clinical_notes')->where('id', $note->id)->value('deleted_at'));
    }

    public function test_rollback_refuses_to_remove_a_multi_clinic_boundary(): void
    {
        Tenant::factory()->create();
        $migration = require database_path('migrations/2026_10_21_000001_add_tenant_foundation.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse a database with multiple clinics.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('original default clinic', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('tenant_user'));
        $this->assertTrue(Schema::hasColumn('patient', 'tenant_id'));
        $this->assertSame(2, Tenant::count());
    }
}
