<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_operator')->default(false);
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('address', 1000)->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->json('branding')->nullable();
            $table->string('provisioning_key', 100)->nullable()->unique();
            $table->string('provisioning_fingerprint', 64)->nullable();
            $table->foreignId('initial_admin_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot DROP FOREIGN KEY; the inline reference leaves with its column.
            DB::statement('ALTER TABLE tenants DROP COLUMN initial_admin_id');
        } else {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('initial_admin_id'));
        }
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['provisioning_key']);
            $table->dropColumn(['contact_email', 'contact_phone', 'address', 'timezone', 'currency', 'branding', 'provisioning_key', 'provisioning_fingerprint']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_operator'));
    }
};
