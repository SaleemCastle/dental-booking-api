<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/** Real PostgreSQL constraints in an isolated schema; never resets the configured database. */
class InsuranceCatalogPostgresTest extends TestCase
{
    private ?string $schema = null;

    private string $previousPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific catalog integrity requires DB_CONNECTION=pgsql.');
        }
        $this->previousPath = DB::selectOne('SHOW search_path')->search_path;
        $this->schema = 'insurance_catalog_test_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA '.$this->schema);
        DB::unprepared('SET search_path TO '.$this->schema);
        // Minimal parent identities: the API suite separately exercises the full migration chain.
        DB::unprepared('CREATE TABLE tenants (id bigint PRIMARY KEY); INSERT INTO tenants VALUES (1), (2)');
        DB::unprepared('CREATE TABLE tenant_user (tenant_id bigint NOT NULL, user_id bigint NOT NULL, UNIQUE (tenant_id, user_id)); INSERT INTO tenant_user VALUES (1, 10), (1, 20), (2, 30)');
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schema !== null && preg_match('/\Ainsurance_catalog_test_[a-f0-9]{16}\z/', $this->schema)) {
                if (DB::connection()->getPdo()->inTransaction()) {
                    DB::connection()->getPdo()->rollBack();
                }
                DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->previousPath]);
                // Only the verified random schema created by this test; never public or a database reset.
                DB::unprepared('DROP SCHEMA '.$this->schema.' CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_postgresql_exclusion_serializes_concurrent_publications_and_rejects_overlap(): void
    {
        $this->fixtures();
        $primary = DB::connection()->getPdo();
        $other = $this->otherConnection();
        $primary->beginTransaction();
        $primary->exec($this->publishSql(1));
        $other->beginTransaction();
        $other->exec("SET LOCAL lock_timeout = '200ms'");
        try {
            $other->exec($this->publishSql(2));
            $this->fail('Concurrent conflicting publication must wait for the first transaction.');
        } catch (PDOException $error) {
            $this->assertSame('55P03', $error->getCode());
        } finally {
            $other->rollBack();
        }
        $primary->commit();
        $other->beginTransaction();
        try {
            $other->exec($this->publishSql(2));
            $this->fail('Committed overlapping publication must be rejected.');
        } catch (PDOException $error) {
            $this->assertSame('23P01', $error->getCode());
            $this->assertStringContainsString('insurance_versions_no_overlap', $error->getMessage());
        } finally {
            $other->rollBack();
        }
        $this->assertSame(1, DB::table('insurance_plan_versions')->where('status', 'published')->count());
        $this->assertSame('reviewed', DB::table('insurance_plan_versions')->where('id', 2)->value('status'));
    }

    public function test_postgresql_guards_reject_raw_cross_tenant_links_self_review_and_truncate(): void
    {
        $this->fixtures();
        $this->reject('INSERT INTO insurance_plans (tenant_id, insurer_id, code, name, lock_version) VALUES (2, 1, \'FOREIGN\', \'Bad\', 1)', ['23503', '23514']);
        DB::unprepared("INSERT INTO insurance_plan_versions (id, tenant_id, insurance_plan_id, version, lock_version, status, name, currency, effective_from, created_by_user_id, last_edited_by_user_id) VALUES (3, 1, 1, 3, 1, 'draft', 'Draft', 'JMD', '2027-01-01', 10, 10)");
        $this->reject("UPDATE insurance_plan_versions SET status = 'reviewed', reviewed_by_user_id = 10, reviewed_at = CURRENT_TIMESTAMP, lock_version = 2 WHERE id = 3", ['23514']);
        $this->reject('UPDATE insurance_plan_versions SET reviewed_by_user_id = 10, lock_version = 3 WHERE id = 1', ['23514']);
        $this->reject('DELETE FROM insurance_version_editors', ['23514']);
        foreach (['insurers', 'insurance_plans', 'insurance_plan_versions', 'insurance_version_editors'] as $table) {
            $this->reject('TRUNCATE '.$table.' CASCADE', ['23514']);
        }
        DB::unprepared($this->publishSql(1));
        $this->reject("UPDATE insurance_plan_versions SET name = 'Rewritten', lock_version = 4 WHERE id = 1", ['23514']);
        $this->reject('DELETE FROM insurance_plan_versions WHERE id = 1', ['23514']);
    }

    public function test_empty_catalog_migration_can_be_rolled_back(): void
    {
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        DB::unprepared('CREATE FUNCTION unrelated_sentinel() RETURNS integer LANGUAGE sql AS \'SELECT 42\'');
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $migration->down();
            $this->assertSame(['unrelated_sentinel'], $this->functionNames());
            $this->assertSame(['tables' => 0, 'triggers' => 0, 'constraints' => 0, 'indexes' => 0, 'sequences' => 0], $this->catalogObjects());
            $this->assertSame(2, DB::table('tenants')->count());
            $migration->up();
            $this->assertSame(['tables' => 4, 'triggers' => 17, 'constraints' => 18, 'indexes' => 14, 'sequences' => 3], $this->catalogObjects());
        }
        $migration->down();
        $this->assertNull(DB::selectOne("SELECT to_regclass('insurance_plans') AS table_name")->table_name);
        $this->assertSame(['unrelated_sentinel'], $this->functionNames());
        $this->assertSame(['tables' => 0, 'triggers' => 0, 'constraints' => 0, 'indexes' => 0, 'sequences' => 0], $this->catalogObjects());
        $this->assertSame(2, DB::table('tenants')->count());
    }

    public function test_table_recreation_replaces_orphaned_functions_and_preserves_integrity(): void
    {
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        $functions = $this->functionNames();
        $this->assertCount(14, $functions);
        $objects = $this->catalogObjects();
        for ($cycle = 0; $cycle < 3; $cycle++) {
            // Fresh table removal retains PL/pgSQL functions. Drop only our four tables,
            // in dependency order, without CASCADE or changing another schema.
            foreach (['insurance_version_editors', 'insurance_plan_versions', 'insurance_plans', 'insurers'] as $table) {
                DB::unprepared('DROP TABLE '.$this->schema.'.'.$table);
            }
            $this->assertSame($functions, $this->functionNames());
            $migration->up();
            $this->assertSame($functions, $this->functionNames());
            $this->assertSame($objects, $this->catalogObjects());
        }
        $this->fixtures();
        DB::unprepared($this->publishSql(1));
        $this->reject($this->publishSql(2), ['23P01']);
        $this->reject("UPDATE insurance_plan_versions SET name = 'Changed', lock_version = 4 WHERE id = 1", ['23514']);
        $this->assertSame(2, DB::table('insurance_version_editors')->count());
    }

    public function test_populated_rollback_preserves_rows_functions_and_constraints(): void
    {
        $this->fixtures();
        $functions = $this->functionNames();
        $objects = $this->catalogObjects();
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        try {
            $migration->down();
            $this->fail('Populated catalog history must prevent rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve insurance catalog history', $error->getMessage());
        }
        $this->assertSame($functions, $this->functionNames());
        $this->assertSame($objects, $this->catalogObjects());
        $this->assertSame(2, DB::table('insurance_plan_versions')->count());
        DB::unprepared($this->publishSql(1));
        $this->reject($this->publishSql(2), ['23P01']);
    }

    private function functionNames(): array
    {
        return array_column(DB::select('SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = ? ORDER BY p.proname', [$this->schema]), 'proname');
    }

    private function catalogObjects(): array
    {
        $tables = "('insurers', 'insurance_plans', 'insurance_plan_versions', 'insurance_version_editors')";
        $scope = "JOIN pg_class c ON c.oid = %s JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN $tables";

        return [
            'tables' => (int) DB::selectOne("SELECT COUNT(*) AS count FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN $tables AND c.relkind = 'r'", [$this->schema])->count,
            'triggers' => (int) DB::selectOne('SELECT COUNT(*) AS count FROM pg_trigger t '.sprintf($scope, 't.tgrelid').' AND NOT t.tgisinternal', [$this->schema])->count,
            'constraints' => (int) DB::selectOne('SELECT COUNT(*) AS count FROM pg_constraint t '.sprintf($scope, 't.conrelid'), [$this->schema])->count,
            'indexes' => (int) DB::selectOne('SELECT COUNT(*) AS count FROM pg_index t '.sprintf($scope, 't.indrelid'), [$this->schema])->count,
            'sequences' => (int) DB::selectOne("SELECT COUNT(*) AS count FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relkind = 'S' AND c.relname IN ('insurers_id_seq', 'insurance_plans_id_seq', 'insurance_plan_versions_id_seq')", [$this->schema])->count,
        ];
    }

    private function fixtures(): void
    {
        DB::unprepared("INSERT INTO insurers (id, tenant_id, code, name, active, lock_version) VALUES (1, 1, 'TEST', 'Test', true, 1)");
        DB::unprepared("INSERT INTO insurance_plans (id, tenant_id, insurer_id, code, name, active, lock_version) VALUES (1, 1, 1, 'PLAN', 'Test', true, 1)");
        foreach ([1, 2] as $id) {
            DB::unprepared("INSERT INTO insurance_plan_versions (id, tenant_id, insurance_plan_id, version, lock_version, status, name, currency, effective_from, effective_to, created_by_user_id, last_edited_by_user_id) VALUES ($id, 1, 1, $id, 1, 'draft', 'Test metadata', 'JMD', '2026-01-01', '2026-12-31', 10, 10)");
            DB::unprepared("UPDATE insurance_plan_versions SET status = 'reviewed', reviewed_by_user_id = 20, reviewed_at = CURRENT_TIMESTAMP, lock_version = 2 WHERE id = $id");
        }
    }

    private function publishSql(int $id): string
    {
        return "UPDATE insurance_plan_versions SET status = 'published', published_by_user_id = 20, published_at = CURRENT_TIMESTAMP, lock_version = 3 WHERE id = $id";
    }

    private function otherConnection(): PDO
    {
        $config = DB::connection()->getConfig();
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO '.$this->schema);

        return $pdo;
    }

    private function reject(string $sql, array $states): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $this->fail('PostgreSQL must reject the integrity violation.');
        } catch (PDOException $error) {
            $this->assertContains($error->getCode(), $states);
        } finally {
            $pdo->rollBack();
        }
    }
}
