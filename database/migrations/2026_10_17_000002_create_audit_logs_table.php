<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('resource_type', 80);
            $table->string('resource_id', 128);
            $table->timestamp('occurred_at', 6);
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('request_metadata')->nullable();
            $table->index(['resource_type', 'resource_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
