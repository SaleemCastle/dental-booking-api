<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->columns() as $table => $date) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index(['tenant_id', $date], $table.'_reporting_date_index'));
        }
    }

    public function down(): void
    {
        foreach ($this->columns() as $table => $date) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($table.'_reporting_date_index'));
        }
    }

    private function columns(): array
    {
        // Appointment clinic/date indexes already exist in the tenant integrity migration.
        return ['invoices' => 'issued_at', 'payments' => 'paid_at'];
    }
};
