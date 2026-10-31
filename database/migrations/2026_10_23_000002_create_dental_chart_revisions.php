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
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Dental chart history supports MySQL, PostgreSQL and SQLite.');
        }

        Schema::create('dental_chart_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('dentition', 10);
            $table->unsignedInteger('version');
            $table->foreignId('dentist_id')->constrained('dentist')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('clinical_note_id')->nullable()->constrained('clinical_notes')->restrictOnDelete();
            $table->json('teeth');
            $table->text('reason');
            $table->dateTime('recorded_at', 6);
            $table->unique(['tenant_id', 'patient_id', 'dentition', 'version'], 'dental_chart_revision_version_unique');
            $table->index(['tenant_id', 'dentist_id', 'recorded_at'], 'dental_chart_provider_history_index');
        });

        $invalid = "NEW.tenant_id IS NULL
            OR NOT EXISTS (SELECT 1 FROM patient WHERE id = NEW.patient_id AND tenant_id = NEW.tenant_id)
            OR NOT EXISTS (SELECT 1 FROM dentist WHERE id = NEW.dentist_id AND tenant_id = NEW.tenant_id)
            OR NOT EXISTS (SELECT 1 FROM tenant_user WHERE user_id = NEW.author_user_id AND tenant_id = NEW.tenant_id)
            OR (NEW.clinical_note_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM clinical_notes WHERE id = NEW.clinical_note_id AND tenant_id = NEW.tenant_id AND patient_id = NEW.patient_id AND dentist_id = NEW.dentist_id AND deleted_at IS NULL))
            OR NEW.dentition NOT IN ('adult', 'primary') OR NEW.version < 1
            OR EXISTS (SELECT 1 FROM dental_chart_revisions WHERE id = NEW.id)
            OR EXISTS (SELECT 1 FROM dental_chart_revisions WHERE tenant_id = NEW.tenant_id AND patient_id = NEW.patient_id AND dentition = NEW.dentition AND version = NEW.version)";
        MigrationGuard::create('dental_chart_insert_guard', 'dental_chart_revisions', 'insert', $invalid, 'Invalid dental chart relationship');
        foreach (['UPDATE', 'DELETE'] as $event) {
            MigrationGuard::create("dental_chart_{$event}_guard", 'dental_chart_revisions', $event, '1 = 1', 'Dental chart history is append-only');
        }
    }

    public function down(): void
    {
        if (DB::table('dental_chart_revisions')->exists()) {
            throw new RuntimeException('Export and back up dental chart history before rolling back this migration.');
        }
        foreach (['insert', 'UPDATE', 'DELETE'] as $event) {
            MigrationGuard::drop("dental_chart_{$event}_guard", 'dental_chart_revisions');
        }
        Schema::drop('dental_chart_revisions');
    }
};
