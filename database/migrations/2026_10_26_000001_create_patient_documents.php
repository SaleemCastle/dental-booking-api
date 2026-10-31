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
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Patient documents support MySQL, PostgreSQL and SQLite.');
        }

        Schema::create('patient_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointment')->restrictOnDelete();
            $table->foreignId('clinical_note_id')->nullable()->constrained('clinical_notes')->restrictOnDelete();
            $table->string('disk', 80);
            $table->string('storage_key')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            // Historical actor IDs survive staff account/membership removal.
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->unsignedBigInteger('archived_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->softDeletes();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id', 'deleted_at', 'archived_at', 'id'], 'patient_documents_browse');
        });

        foreach (['insert', 'update'] as $event) {
            $checks = [
                'NEW.tenant_id IS NULL',
                'NEW.size_bytes < 1',
                '(NEW.archived_at IS NULL AND NEW.archived_by_user_id IS NOT NULL)',
                '(NEW.archived_at IS NOT NULL AND NEW.archived_by_user_id IS NULL)',
                '(NEW.deleted_at IS NULL AND NEW.deleted_by_user_id IS NOT NULL)',
                '(NEW.deleted_at IS NOT NULL AND NEW.deleted_by_user_id IS NULL)',
                '(NEW.purged_at IS NOT NULL AND NEW.deleted_at IS NULL)',
                'NOT EXISTS (SELECT 1 FROM patient WHERE id = NEW.patient_id AND tenant_id = NEW.tenant_id)',
                '(NEW.appointment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM appointment WHERE id = NEW.appointment_id AND tenant_id = NEW.tenant_id AND patient_id = NEW.patient_id))',
                '(NEW.clinical_note_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM clinical_notes WHERE id = NEW.clinical_note_id AND tenant_id = NEW.tenant_id AND patient_id = NEW.patient_id))',
                '(NEW.appointment_id IS NOT NULL AND NEW.clinical_note_id IS NOT NULL AND EXISTS (SELECT 1 FROM clinical_notes WHERE id = NEW.clinical_note_id AND appointment_id IS NOT NULL AND appointment_id <> NEW.appointment_id))',
            ];
            foreach (['uploaded_by_user_id', 'archived_by_user_id', 'deleted_by_user_id'] as $column) {
                $changed = $event === 'insert' ? '1 = 1' : "(OLD.$column IS NULL OR NEW.$column <> OLD.$column)";
                $checks[] = "(NEW.$column IS NOT NULL AND $changed AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.$column))";
            }
            if ($event === 'update') {
                foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id', 'purged_at'] as $column) {
                    $checks[] = "(OLD.$column IS NOT NULL AND (NEW.$column IS NULL OR NEW.$column <> OLD.$column))";
                }
                foreach (['tenant_id', 'patient_id', 'disk', 'storage_key', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by_user_id'] as $column) {
                    $checks[] = "NEW.$column <> OLD.$column";
                }
                foreach (['appointment_id', 'clinical_note_id'] as $column) {
                    $checks[] = "(NEW.$column <> OLD.$column OR (NEW.$column IS NULL AND OLD.$column IS NOT NULL) OR (NEW.$column IS NOT NULL AND OLD.$column IS NULL))";
                }
            }
            $condition = implode(' OR ', $checks);
            $trigger = 'patient_documents_guard_'.$event;
            MigrationGuard::create($trigger, 'patient_documents', $event, $condition, 'Invalid patient document ownership or metadata');
        }

        foreach ([
            'appointment' => 'EXISTS (SELECT 1 FROM patient_documents WHERE appointment_id = OLD.id AND patient_id <> NEW.patient_id)',
            'clinical_notes' => 'EXISTS (SELECT 1 FROM patient_documents WHERE clinical_note_id = OLD.id AND (patient_id <> NEW.patient_id OR (appointment_id IS NOT NULL AND NEW.appointment_id IS NOT NULL AND appointment_id <> NEW.appointment_id)))',
        ] as $table => $condition) {
            $trigger = $table.'_document_links_guard';
            MigrationGuard::create($trigger, $table, 'update', $condition, 'Patient document links must be preserved');
        }
    }

    public function down(): void
    {
        if (DB::table('patient_documents')->exists()) {
            throw new RuntimeException('Back up and explicitly recover document metadata and objects before removing their schema.');
        }
        foreach (['appointment', 'clinical_notes'] as $table) {
            MigrationGuard::drop($table.'_document_links_guard', $table);
        }
        foreach (['insert', 'update'] as $event) {
            MigrationGuard::drop('patient_documents_guard_'.$event, 'patient_documents');
        }
        Schema::dropIfExists('patient_documents');
    }
};
