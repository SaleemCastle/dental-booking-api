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
            throw new RuntimeException('Provider availability supports MySQL, PostgreSQL and SQLite.');
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
            MigrationGuard::create($trigger, 'provider_availabilities', $event, $condition, 'Invalid availability ownership');
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update'] as $event) {
            MigrationGuard::drop('provider_availabilities_guard_'.$event, 'provider_availabilities');
        }
        Schema::dropIfExists('provider_availabilities');
    }
};
