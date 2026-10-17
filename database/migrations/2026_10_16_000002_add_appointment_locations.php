<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('chairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['room_id', 'name']);
        });

        Schema::table('appointment', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('chair_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['room_id', 'appointment_date_time']);
            $table->index(['chair_id', 'appointment_date_time']);
        });
    }

    public function down(): void
    {
        Schema::table('appointment', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'appointment_date_time']);
            $table->dropIndex(['chair_id', 'appointment_date_time']);
            $table->dropConstrainedForeignId('chair_id');
            $table->dropConstrainedForeignId('room_id');
        });

        Schema::dropIfExists('chairs');
        Schema::dropIfExists('rooms');
    }
};
