<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $ownedTables = ['roles', 'patient', 'dentist', 'treatments', 'rooms', 'chairs', 'appointment', 'invoices', 'payments', 'messages', 'referral', 'clinical_notes', 'tasks', 'audit_logs'];

    private array $uniqueColumns = ['roles' => 'slug', 'rooms' => 'name', 'invoices' => 'invoice_number'];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Default Clinic', 'slug' => 'default-clinic', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'tenant_id']);
        });
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($tenantId): void {
            DB::table('tenant_user')->insert($users->map(fn ($user) => [
                'tenant_id' => $tenantId, 'user_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
        });

        foreach ($this->ownedTables as $name) {
            Schema::table($name, function (Blueprint $table) use ($tenantId): void {
                $table->foreignId('tenant_id')->default($tenantId)->constrained()->restrictOnDelete();
                $table->index(['tenant_id', 'id']);
            });
        }
        foreach ($this->uniqueColumns as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column): void {
                $table->unique(['tenant_id', $column]);
                $table->dropUnique([$column]);
            });
        }
        Schema::table('roles', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildRoleUsers($tenantId);
        } else {
            Schema::table('role_user', function (Blueprint $table) use ($tenantId): void {
                $table->foreignId('tenant_id')->default($tenantId)->constrained()->restrictOnDelete();
                $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_user')->cascadeOnDelete();
                $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('tenants')->count() !== 1 || DB::table('tenants')->value('slug') !== 'default-clinic') {
            throw new RuntimeException('Tenant rollback requires only the original default clinic. Export and reconcile additional clinic data first.');
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildRoleUsers(null);
        } else {
            Schema::table('role_user', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'user_id']);
                $table->dropForeign(['tenant_id', 'role_id']);
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
        foreach ($this->uniqueColumns as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column): void {
                $table->dropUnique(['tenant_id', $column]);
                $table->unique([$column]);
            });
        }
        foreach (array_reverse($this->ownedTables) as $name) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['tenant_id', 'id']));
                DB::statement('ALTER TABLE "'.$name.'" DROP COLUMN "tenant_id"');
            } else {
                Schema::table($name, function (Blueprint $table): void {
                    $table->dropIndex(['tenant_id', 'id']);
                    $table->dropConstrainedForeignId('tenant_id');
                });
            }
        }
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }

    private function rebuildRoleUsers(?int $tenantId): void
    {
        $name = $tenantId === null ? 'legacy_role_user' : 'tenant_role_user';
        Schema::create($name, function (Blueprint $table) use ($tenantId): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'user_id']);
            $table->index(['user_id', 'role_id']);
            if ($tenantId !== null) {
                $table->foreignId('tenant_id')->default($tenantId)->constrained('tenants')->restrictOnDelete();
                $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('tenant_user')->cascadeOnDelete();
                $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            }
        });
        $columns = ['id', 'role_id', 'user_id', 'created_at', 'updated_at'];
        DB::table($name)->insertUsing($columns, DB::table('role_user')->select($columns));
        Schema::drop('role_user');
        Schema::rename($name, 'role_user');
    }
};
