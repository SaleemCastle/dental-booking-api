<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patient')->cascadeOnDelete();
            $table->foreignId('dentist_id')->constrained('dentist')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointment')->nullOnDelete();
            $table->text('note_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_notes');
    }
};
