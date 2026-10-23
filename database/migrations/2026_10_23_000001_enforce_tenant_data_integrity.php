<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Keep this migration self-contained: future model changes must not change recovery.
    private array $relationships = [
        'roles' => [],
        'patient' => [],
        'dentist' => [],
        'treatments' => [],
        'rooms' => [],
        'chairs' => ['room_id' => 'rooms'],
        'appointment' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'treatment_id' => 'treatments', 'room_id' => 'rooms', 'chair_id' => 'chairs'],
        'invoices' => ['patient_id' => 'patient', 'appointment_id' => 'appointment'],
        'payments' => ['invoice_id' => 'invoices'],
        'messages' => ['sender_id' => 'patient', 'receiver_id' => 'patient'],
        'referral' => ['patient_id' => 'patient', 'dentist_id' => 'dentist'],
        'clinical_notes' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'appointment_id' => 'appointment'],
        'tasks' => ['patient_id' => 'patient', 'appointment_id' => 'appointment', 'assignee_id' => 'tenant_user'],
        'audit_logs' => [],
    ];

    private array $indexes = [
        'patient' => [['tenant_id', 'archived_at', 'id']],
        'dentist' => [['tenant_id', 'email']],
        'treatments' => [['tenant_id', 'active', 'name']],
        'appointment' => [
            ['tenant_id', 'appointment_date_time', 'id'],
            ['tenant_id', 'dentist_id', 'appointment_date_time'],
            ['tenant_id', 'room_id', 'appointment_date_time'],
            ['tenant_id', 'chair_id', 'appointment_date_time'],
        ],
        'invoices' => [['tenant_id', 'status']],
        'clinical_notes' => [['tenant_id', 'patient_id', 'created_at']],
        'tasks' => [
            ['tenant_id', 'status', 'due_date'],
            ['tenant_id', 'assignee_id', 'due_date'],
            ['tenant_id', 'patient_id', 'due_date'],
        ],
        'audit_logs' => [
            ['tenant_id', 'occurred_at', 'id'],
            ['tenant_id', 'actor_id', 'occurred_at'],
            ['tenant_id', 'resource_type', 'resource_id', 'occurred_at'],
        ],
    ];

    // Phase 1 foreignIdFor() columns did not actually create these foreign keys.
    private array $legacyForeignKeys = [
        'appointment' => ['patient_id' => 'patient', 'dentist_id' => 'dentist'],
        'referral' => ['patient_id' => 'patient', 'dentist_id' => 'dentist'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Tenant integrity migration supports MySQL and SQLite.');
        }

        // Check before any DDL. Never repair inconsistent clinical links by deleting data.
        foreach ($this->relationships as $name => $relationships) {
            $invalid = DB::table($name.' as child')->whereNotExists(fn ($query) => $query
                ->selectRaw('1')->from('tenants')->whereColumn('tenants.id', 'child.tenant_id'))->exists();
            foreach ($relationships as $column => $parent) {
                $key = $parent === 'tenant_user' ? 'user_id' : 'id';
                $invalid = $invalid || DB::table($name.' as child')->whereNotNull('child.'.$column)
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from($parent.' as parent')
                        ->whereColumn('parent.'.$key, 'child.'.$column)
                        ->whereColumn('parent.tenant_id', 'child.tenant_id'))->exists();
            }
            if ($invalid) {
                throw new RuntimeException('Tenant integrity preflight failed for '.$name.'. Restore or reconcile the records before retrying; no data was changed.');
            }
        }

        foreach ($this->indexes as $name => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table($name, fn (Blueprint $table) => $table->index($columns));
            }
        }

        foreach ($this->relationships as $name => $relationships) {
            $checks = ['NEW.tenant_id IS NULL OR NOT EXISTS (SELECT 1 FROM tenants WHERE id = NEW.tenant_id)'];
            foreach ($relationships as $column => $parent) {
                $key = $parent === 'tenant_user' ? 'user_id' : 'id';
                $checks[] = "NEW.$column IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `$parent` WHERE `$key` = NEW.`$column` AND tenant_id = NEW.tenant_id)";
            }

            // Triggers retain the Phase 1 FK cascade/SET NULL actions, which cannot
            // safely be replaced by SET NULL on (tenant_id, optional_record_id).
            $insertChecks = $checks;
            $updateChecks = ['NEW.tenant_id <> OLD.tenant_id', ...$checks];
            $attributions = match ($name) {
                'clinical_notes' => ['author_user_id', 'updated_by_user_id', 'deleted_by_user_id'],
                'patient' => ['archived_by_user_id'],
                default => [],
            };
            foreach ($attributions as $column) {
                $invalid = "NEW.`$column` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE user_id = NEW.`$column` AND tenant_id = NEW.tenant_id)";
                $insertChecks[] = $invalid;
                // Historical attribution survives a user's membership removal.
                $updateChecks[] = "(OLD.`$column` IS NULL OR NEW.`$column` <> OLD.`$column`) AND ($invalid)";
            }
            $this->createGuard($name, 'insert', $insertChecks);
            $this->createGuard($name, 'update', $updateChecks);
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ($this->legacyForeignKeys as $name => $relationships) {
                foreach ($relationships as $column => $parent) {
                    Schema::table($name, fn (Blueprint $table) => $table
                        ->foreign($column, $name.'_'.$column.'_integrity_foreign')->references('id')->on($parent)->restrictOnDelete());
                }
            }
            Schema::table('tasks', fn (Blueprint $table) => $table
                ->foreign(['tenant_id', 'assignee_id'], 'tasks_tenant_assignee_foreign')
                ->references(['tenant_id', 'user_id'])->on('tenant_user')->restrictOnDelete());
        } else {
            // SQLite cannot add a foreign key without rebuilding the whole table.
            $assigned = 'EXISTS (SELECT 1 FROM tasks WHERE tenant_id = OLD.tenant_id AND assignee_id = OLD.user_id)';
            $this->createGuard('tenant_user', 'delete', [$assigned]);
            $this->createGuard('tenant_user', 'update', ["(NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id) AND $assigned"]);
            $owned = array_map(fn ($name) => "EXISTS (SELECT 1 FROM `$name` WHERE tenant_id = OLD.id)", array_keys($this->relationships));
            $this->createGuard('tenants', 'delete', $owned);
            $this->createGuard('tenants', 'update', ['NEW.id <> OLD.id AND ('.implode(' OR ', $owned).')']);
            foreach (['patient' => 'patient_id', 'dentist' => 'dentist_id'] as $parent => $column) {
                $linked = "EXISTS (SELECT 1 FROM appointment WHERE `$column` = OLD.id) OR EXISTS (SELECT 1 FROM referral WHERE `$column` = OLD.id)";
                $this->createGuard($parent, 'delete', [$linked]);
                DB::unprepared("CREATE TRIGGER `tenant_integrity_{$parent}_key_update` BEFORE UPDATE ON `$parent` FOR EACH ROW WHEN NEW.id <> OLD.id AND ($linked) BEGIN SELECT RAISE(ABORT, 'Tenant integrity violation'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign('tasks_tenant_assignee_foreign'));
            foreach ($this->legacyForeignKeys as $name => $relationships) {
                foreach ($relationships as $column => $parent) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropForeign($name.'_'.$column.'_integrity_foreign'));
                }
            }
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_integrity_tenant_user_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_integrity_tenant_user_update');
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_integrity_tenants_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS tenant_integrity_tenants_update');
            foreach (['patient', 'dentist'] as $parent) {
                DB::unprepared("DROP TRIGGER IF EXISTS tenant_integrity_{$parent}_delete");
                DB::unprepared("DROP TRIGGER IF EXISTS tenant_integrity_{$parent}_key_update");
            }
        }
        foreach ($this->relationships as $name => $relationships) {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS tenant_integrity_{$name}_{$event}");
            }
        }
        foreach ($this->indexes as $name => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex($columns));
            }
        }
    }

    private function createGuard(string $table, string $event, array $checks): void
    {
        $condition = implode(' OR ', array_map(fn ($check) => '('.$check.')', $checks));
        $name = 'tenant_integrity_'.$table.'_'.$event;
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER `$name` BEFORE $event ON `$table` FOR EACH ROW WHEN $condition BEGIN SELECT RAISE(ABORT, 'Tenant integrity violation'); END");
        } else {
            DB::unprepared("CREATE TRIGGER `$name` BEFORE $event ON `$table` FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tenant integrity violation'; END IF; END");
        }
    }
};
