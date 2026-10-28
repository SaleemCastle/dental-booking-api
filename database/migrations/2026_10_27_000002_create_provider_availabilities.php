<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Provider availability supports MySQL and SQLite.');
        }

        Schema::create('provider_availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('dentist_id')->unique()->constrained('dentist')->cascadeOnDelete();
            $table->json('weekly_hours');
            $table->json('breaks');
            $table->json('exceptions');
            $table->timestamps();
            $table->index(['tenant_id', 'dentist_id']);
        });

        foreach (['insert', 'update'] as $event) {
            $condition = 'NOT EXISTS (SELECT 1 FROM dentist WHERE id = NEW.dentist_id AND tenant_id = NEW.tenant_id)';
            if ($event === 'update') {
                $condition .= ' OR NEW.tenant_id <> OLD.tenant_id OR NEW.dentist_id <> OLD.dentist_id';
            }
            $trigger = 'provider_availabilities_guard_'.$event;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER $trigger BEFORE $event ON provider_availabilities WHEN $condition BEGIN SELECT RAISE(ABORT, 'Invalid availability ownership'); END");
            } else {
                DB::unprepared("CREATE TRIGGER $trigger BEFORE $event ON provider_availabilities FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid availability ownership'; END IF; END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_availabilities');
    }
};
