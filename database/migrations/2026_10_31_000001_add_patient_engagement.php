<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // SQLite cannot change nullability in place. Preserve columns, data, indexes and triggers.
        if (DB::getDriverName() === 'sqlite') {
            $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'clinic_notifications'")->sql;
            $objects = DB::select("SELECT name, type, sql FROM sqlite_master WHERE sql IS NOT NULL AND ((tbl_name = 'clinic_notifications' AND type = 'index') OR (type = 'trigger' AND sql LIKE '%clinic_notifications%'))");
            $definition = preg_replace('/("?invoice_id"?\s+integer)\s+not null/i', '$1', $definition);
            $definition = preg_replace('/CREATE TABLE ["`]?clinic_notifications["`]?/i', 'CREATE TABLE clinic_notifications_rebuild', $definition, 1);
            Schema::disableForeignKeyConstraints();
            try {
                DB::transaction(function () use ($definition, $objects) {
                    foreach ($objects as $object) {
                        if ($object->type === 'trigger') {
                            DB::unprepared('DROP TRIGGER IF EXISTS '.$object->name);
                        }
                    }
                    DB::statement($definition);
                    DB::statement('INSERT INTO clinic_notifications_rebuild SELECT * FROM clinic_notifications');
                    DB::statement('DROP TABLE clinic_notifications');
                    DB::statement('ALTER TABLE clinic_notifications_rebuild RENAME TO clinic_notifications');
                    foreach ($objects as $object) {
                        DB::unprepared($object->sql);
                    }
                });
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        } else {
            DB::statement('ALTER TABLE clinic_notifications MODIFY invoice_id BIGINT UNSIGNED NULL');
        }
        Schema::table('appointment', fn (Blueprint $table) => $table->unsignedInteger('communication_revision')->default(0));
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('patient_reminder_at')->nullable();
            $table->unsignedInteger('communication_revision')->default(0);
        });
        Schema::table('clinic_notifications', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->constrained('patient')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointment')->restrictOnDelete();
            // Keep task history even when the staff task is deleted; worker suppresses missing sources.
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->index(['tenant_id', 'patient_id', 'id']);
            $table->index(['tenant_id', 'appointment_id', 'id']);
        });
        DB::statement('UPDATE clinic_notifications SET patient_id = (SELECT patient_id FROM invoices WHERE invoices.id = clinic_notifications.invoice_id), appointment_id = (SELECT appointment_id FROM invoices WHERE invoices.id = clinic_notifications.invoice_id)');
        foreach (['insert', 'update'] as $event) {
            $checks = [];
            foreach (['patient_id' => 'patient', 'appointment_id' => 'appointment', 'task_id' => 'tasks'] as $column => $parent) {
                $checks[] = "(NEW.$column IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $parent WHERE id = NEW.$column AND tenant_id = NEW.tenant_id))";
            }
            $checks[] = '(NEW.appointment_id IS NOT NULL AND NEW.patient_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM appointment WHERE id = NEW.appointment_id AND patient_id = NEW.patient_id))';
            $checks[] = '(NEW.task_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tasks WHERE id = NEW.task_id AND patient_id = NEW.patient_id AND (appointment_id = NEW.appointment_id OR (appointment_id IS NULL AND NEW.appointment_id IS NULL))))';
            $checks[] = '(NEW.invoice_id IS NOT NULL AND NEW.patient_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM invoices WHERE id = NEW.invoice_id AND patient_id = NEW.patient_id))';
            $condition = implode(' OR ', $checks);
            DB::unprepared(DB::getDriverName() === 'mysql'
                ? "CREATE TRIGGER notifications_engagement_$event BEFORE $event ON clinic_notifications FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Communication source mismatch'; END IF; END"
                : "CREATE TRIGGER notifications_engagement_$event BEFORE $event ON clinic_notifications FOR EACH ROW WHEN $condition BEGIN SELECT RAISE(ABORT, 'Communication source mismatch'); END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Patient engagement history requires an explicit backup and data migration before rollback.');
    }
};
