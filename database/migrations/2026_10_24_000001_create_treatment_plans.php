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
            throw new RuntimeException('Treatment planning supports MySQL, PostgreSQL and SQLite.');
        }

        Schema::create('treatment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patient')->restrictOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id', 'status', 'id']);
        });

        Schema::create('treatment_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('treatment_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            $table->string('treatment_name');
            $table->foreignId('dentist_id')->constrained('dentist')->restrictOnDelete();
            $table->json('tooth_context')->nullable();
            $table->decimal('catalog_price', 10, 2);
            $table->decimal('estimated_price', 10, 2);
            $table->unsignedInteger('sequence')->default(1);
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['proposed', 'in_progress', 'completed', 'cancelled'])->default('proposed');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'treatment_plan_id', 'sequence', 'id'], 'treatment_plan_items_order_index');
        });

        // Match the existing tenant integrity guards, including raw SQL writes.
        foreach ([
            'treatment_plans' => ['patient_id' => 'patient'],
            'treatment_plan_items' => ['treatment_plan_id' => 'treatment_plans', 'treatment_id' => 'treatments', 'dentist_id' => 'dentist'],
        ] as $table => $relationships) {
            $checks = ['NEW.tenant_id IS NULL'];
            foreach ($relationships as $column => $parent) {
                $checks[] = "NOT EXISTS (SELECT 1 FROM $parent WHERE id = NEW.$column AND tenant_id = NEW.tenant_id)";
            }
            foreach (['insert', 'update'] as $event) {
                $eventChecks = $checks;
                if ($event === 'update') {
                    $eventChecks[] = 'NEW.tenant_id <> OLD.tenant_id';
                    foreach ($table === 'treatment_plans' ? ['patient_id'] : ['treatment_plan_id', 'treatment_id', 'catalog_price', 'treatment_name'] as $column) {
                        $eventChecks[] = "NEW.$column <> OLD.$column";
                    }
                }
                $condition = implode(' OR ', $eventChecks);
                $trigger = $table.'_guard_'.$event;
                MigrationGuard::create($trigger, $table, $event, $condition, 'Invalid treatment plan ownership or snapshot');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('treatment_plans')->exists() || DB::table('treatment_plan_items')->exists()) {
            throw new RuntimeException('Back up and explicitly recover treatment planning history before removing its schema.');
        }
        foreach (['treatment_plan_items', 'treatment_plans'] as $table) {
            foreach (['insert', 'update'] as $event) {
                MigrationGuard::drop($table.'_guard_'.$event, $table);
            }
        }
        Schema::dropIfExists('treatment_plan_items');
        Schema::dropIfExists('treatment_plans');
    }
};
