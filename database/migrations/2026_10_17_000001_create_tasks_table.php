<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained('patient')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointment')->nullOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('pending');
            $table->text('description');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_id', 'status', 'due_date']);
            $table->index(['status', 'due_date']);
            $table->index(['patient_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
