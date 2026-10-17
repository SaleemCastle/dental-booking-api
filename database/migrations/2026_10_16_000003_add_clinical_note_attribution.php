<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->foreignId('author_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->softDeletes();
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->dropIndex(['patient_id', 'created_at']);
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropConstrainedForeignId('author_user_id');
            $table->dropSoftDeletes();
        });
    }
};
