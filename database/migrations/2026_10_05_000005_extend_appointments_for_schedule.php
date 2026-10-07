<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment', function (Blueprint $table) {
            $table->foreignId('treatment_id')->nullable()->after('dentist_id')->constrained('treatments')->nullOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->default(30)->after('appointment_date_time');
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('checked_in_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->index(['dentist_id', 'appointment_date_time']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointment', function (Blueprint $table) {
            $table->dropIndex(['dentist_id', 'appointment_date_time']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('treatment_id');
            $table->dropColumn([
                'duration_minutes',
                'checked_in_at',
                'completed_at',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
