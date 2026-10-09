<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient', function (Blueprint $table) {
            $table->string('emergency_contact_name')->nullable()->after('notes');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            $table->json('allergies')->nullable()->after('emergency_contact_relationship');
            $table->json('medications')->nullable()->after('allergies');
            $table->json('medical_alerts')->nullable()->after('medications');
        });
    }

    public function down(): void
    {
        Schema::table('patient', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_relationship',
                'allergies',
                'medications',
                'medical_alerts',
            ]);
        });
    }
};
