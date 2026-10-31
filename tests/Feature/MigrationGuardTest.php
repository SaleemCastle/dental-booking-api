<?php

namespace Tests\Feature;

use App\Support\MigrationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshTestDatabase()
    {
        // MySQL DDL commits implicitly, so this schema test needs a fresh database.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_guards_can_be_removed_and_recreated_without_losing_valid_writes(): void
    {
        Schema::create('migration_guard_probe', function (Blueprint $table): void {
            $table->id();
            $table->integer('value');
        });

        try {
            foreach (['insert', 'update', 'delete'] as $event) {
                $name = 'migration_probe_'.$event;
                $row = $event === 'delete' ? 'OLD' : 'NEW';
                MigrationGuard::create($name, 'migration_guard_probe', $event, "$row.value < 0", 'Probe rejected');
                MigrationGuard::drop($name, 'migration_guard_probe');
                MigrationGuard::create($name, 'migration_guard_probe', $event, "$row.value < 0", 'Probe rejected');
            }

            $id = DB::table('migration_guard_probe')->insertGetId(['value' => 1]);
            DB::table('migration_guard_probe')->where('id', $id)->update(['value' => 2]);
            $this->assertDatabaseHas('migration_guard_probe', ['id' => $id, 'value' => 2]);

            try {
                // Roll back the rejected statement before querying again on PostgreSQL.
                DB::transaction(fn () => DB::table('migration_guard_probe')->insert(['value' => -1]));
                $this->fail('The insert guard did not reject an invalid value.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Probe rejected', $exception->getMessage());
            }

            $this->assertSame(1, DB::table('migration_guard_probe')->where('id', $id)->delete());
            $this->assertDatabaseCount('migration_guard_probe', 0);
        } finally {
            foreach (['insert', 'update', 'delete'] as $event) {
                MigrationGuard::drop('migration_probe_'.$event, 'migration_guard_probe');
            }
            Schema::dropIfExists('migration_guard_probe');
        }
    }
}
