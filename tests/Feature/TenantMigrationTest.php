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
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        // MySQL DDL commits implicitly. Migration round-trips must use a clean
        // dedicated schema per test rather than a transaction around each test.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_phase_one_records_ids_links_and_history_survive_migration(): void
    {
        // Exercise the Phase 1 schema before the later billing foundation exists.
        $operations = require database_path('migrations/2026_10_29_000001_add_tenant_operations.php');
        $operations->down();
        $communications = require database_path('migrations/2026_10_28_000003_create_clinic_communications.php');
        $communications->down();
        $reconciliation = require database_path('migrations/2026_10_28_000002_add_payment_reconciliation.php');
        $reconciliation->down();
        $paymentLinks = require database_path('migrations/2026_10_28_000001_create_payment_links.php');
        $paymentLinks->down();
        $reporting = require database_path('migrations/2026_10_28_000001_add_reporting_indexes.php');
        $reporting->down();
        $availability = require database_path('migrations/2026_10_27_000002_create_provider_availabilities.php');
        $availability->down();
        $billing = require database_path('migrations/2026_10_27_000001_build_billing_foundation.php');
        $billing->down();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        User::factory()->create();
        $patient = Patient::factory()->create();
        $dentist = Dentist::factory()->create();
        $treatment = Treatment::factory()->create();
        $appointment = \App\Models\Appointment::factory()->for($patient)->for($dentist)->for($treatment)->create();
        ClinicalNote::factory()->for($patient)->for($dentist)->for($appointment)->create();
        $invoiceId = DB::table('invoices')->insertGetId(['patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'invoice_number' => 'LEGACY', 'amount_due' => 100, 'amount_paid' => 25, 'status' => 'partial', 'created_at' => now(), 'updated_at' => now(), 'tenant_id' => $patient->tenant_id]);
        DB::table('payments')->insert(['invoice_id' => $invoiceId, 'amount' => 25, 'status' => 'paid', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'tenant_id' => $patient->tenant_id]);
        User::firstOrFail()->createToken('legacy-token');
        $patient = Patient::firstOrFail();
        $patient->archive(User::firstOrFail());
        Treatment::firstOrFail()->update(['active' => false]);
        Task::factory()->create();
        Message::create(['sender_id' => $patient->id, 'receiver_id' => $patient->id, 'message_text' => 'Historical message']);
        Referral::create(['patient_id' => $patient->id, 'dentist_id' => Dentist::firstOrFail()->id, 'referred_to_email' => 'historical@example.test', 'referral_date' => '2026-11-01', 'referral_notes' => 'Historical referral']);
        app(AuditLogService::class)->record('created', 'patients', $patient->id, User::firstOrFail()->id);
        $note = ClinicalNote::firstOrFail();
        $note->delete();
        $documents = require database_path('migrations/2026_10_26_000001_create_patient_documents.php');
        $documents->down();
        $plans = require database_path('migrations/2026_10_24_000001_create_treatment_plans.php');
        $plans->down();
        $charts = require database_path('migrations/2026_10_23_000002_create_dental_chart_revisions.php');
        $charts->down();
        $integrity = require database_path('migrations/2026_10_23_000001_enforce_tenant_data_integrity.php');
        $integrity->down();
        $migration = require database_path('migrations/2026_10_21_000001_add_tenant_foundation.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('tenants'));
        $tables = ['users', 'personal_access_tokens', 'roles', 'role_user', 'permissions', 'permission_role', 'patient', 'dentist', 'treatments', 'rooms', 'chairs', 'appointment', 'invoices', 'payments', 'messages', 'referral', 'clinical_notes', 'tasks', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $migration->up();
        $integrity->up();
        $charts->up();
        $plans->up();
        $documents->up();
        $availability->up();
        $reporting->up();
        $paymentLinks->up();

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
        // Verify preserved Phase 1 columns before applying newer additive settlement/communication fields.
        $reconciliation->up();
        $communications->up();
        $operations->up();
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

    public function test_integrity_migration_refuses_invalid_legacy_links_before_changing_schema(): void
    {
        $patient = Patient::factory()->create();
        $appointment = \App\Models\Appointment::factory()->create();
        $integrity = require database_path('migrations/2026_10_23_000001_enforce_tenant_data_integrity.php');
        $integrity->down();
        $foreign = Tenant::factory()->create();
        DB::table('patient')->where('id', $patient->id)->update(['tenant_id' => $foreign->id]);
        DB::table('appointment')->where('id', $appointment->id)->update(['patient_id' => $patient->id]);
        try {
            $integrity->up();
            $this->fail('Invalid legacy relationships must be reconciled explicitly.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preflight failed for appointment', $exception->getMessage());
        }
        $this->assertSame($patient->id, DB::table('appointment')->where('id', $appointment->id)->value('patient_id'));
        // A corrected link can be migrated without cleaning up partial DDL.
        DB::table('patient')->where('id', $patient->id)->update(['tenant_id' => $appointment->tenant_id]);
        $integrity->up();
        $this->assertSame($appointment->tenant_id, DB::table('patient')->where('id', $patient->id)->value('tenant_id'));
    }

    public function test_integrity_rollback_and_reapply_preserve_all_clinics(): void
    {
        $local = Patient::factory()->create();
        $foreign = Tenant::factory()->create();
        $other = app(\App\Tenancy\TenantContext::class)->run($foreign, fn () => Patient::factory()->create());
        $before = DB::table('patient')->orderBy('id')->get();
        $integrity = require database_path('migrations/2026_10_23_000001_enforce_tenant_data_integrity.php');
        $integrity->down();
        $this->assertEquals($before, DB::table('patient')->orderBy('id')->get());
        $this->assertTrue(Schema::hasTable('tenant_user'));
        $integrity->up();
        $this->assertEquals($before, DB::table('patient')->orderBy('id')->get());
        $this->assertNotSame($local->tenant_id, $other->tenant_id);
    }
}
