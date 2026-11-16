<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/** Independent connections prove serialization in an isolated, disposable schema. */
class InsuranceBenefitRulesPostgresTest extends TestCase
{
    private ?string $schema = null;

    private string $previousPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->previousPath = DB::selectOne('SHOW search_path')->search_path;
        $this->schema = 'benefit_test_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA '.$this->schema);
        DB::unprepared('SET search_path TO '.$this->schema);
        // Minimal FK parents; the API tests exercise the complete migration chain.
        DB::unprepared(<<<'SQL'
CREATE TABLE tenants(id bigint PRIMARY KEY);
INSERT INTO tenants VALUES(1),(2);
CREATE TABLE users(id bigint PRIMARY KEY);
INSERT INTO users VALUES(10),(20);
CREATE TABLE tenant_user(tenant_id bigint,user_id bigint,UNIQUE(tenant_id,user_id));
INSERT INTO tenant_user VALUES(1,10),(1,20);
CREATE TABLE patient_insurer_associations(id bigint PRIMARY KEY,tenant_id bigint,lock_version integer,UNIQUE(tenant_id,id));
CREATE TABLE patient_insurer_revisions(id bigint PRIMARY KEY,tenant_id bigint,association_id bigint,version integer,UNIQUE(tenant_id,association_id,id));
CREATE TABLE patient_insurance_policy_revisions(id bigint PRIMARY KEY,tenant_id bigint,association_id bigint,UNIQUE(tenant_id,association_id,id));
SQL);
        (require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php'))->up();
        (require database_path('migrations/2026_11_16_000001_create_insurance_benefit_rules.php'))->up();
        DB::unprepared(<<<'SQL'
INSERT INTO insurers(id,tenant_id,code,name,lock_version) VALUES(1,1,'TEST','Synthetic',1);
INSERT INTO insurance_plans(id,tenant_id,insurer_id,code,name,lock_version) VALUES(1,1,1,'TEST','Synthetic',1);
INSERT INTO insurance_plan_versions(id,tenant_id,insurance_plan_id,version,lock_version,status,name,currency,effective_from,created_by_user_id,last_edited_by_user_id)
 VALUES(1,1,1,1,1,'draft','Synthetic','JMD','2030-01-01',10,10);
SQL);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schema !== null && preg_match('/\Abenefit_test_[a-f0-9]{16}\z/', $this->schema)) {
                if (DB::connection()->getPdo()->inTransaction()) {
                    DB::connection()->getPdo()->rollBack();
                }
                DB::select('SELECT set_config(?, ?, false)', ['search_path', $this->previousPath]);
                DB::unprepared('DROP SCHEMA '.$this->schema.' CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function other(): PDO
    {
        $config = DB::connection()->getConfig();
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO '.$this->schema);

        return $pdo;
    }

    private function insert(int $id, ?int $previous = null): string
    {
        return "INSERT INTO insurance_benefit_rules(id,tenant_id,plan_version_id,supersedes_id,revision,source,scope,coverage,benefit_type,observed_at,provenance,reason,actor_id) VALUES($id,1,1,".($previous ?? 'NULL').','.($previous === null ? 1 : 2).",'insurer_plan','all','unknown','unknown','2020-01-01','synthetic encrypted provenance','synthetic encrypted reason',10)";
    }

    private function rejected(PDO $pdo, string $sql, string $state): void
    {
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL lock_timeout='200ms'");
        try {
            $pdo->exec($sql);
            $this->fail('Expected PostgreSQL guard or serialization failure.');
        } catch (PDOException $e) {
            $this->assertSame($state, $e->getCode());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_rule_insert_serializes_review_and_published_version_blocks_later_insert(): void
    {
        $primary = DB::connection()->getPdo();
        $other = $this->other();
        $review = "UPDATE insurance_plan_versions SET status='reviewed',reviewed_by_user_id=20,reviewed_at=CURRENT_TIMESTAMP,lock_version=lock_version+1 WHERE id=1";
        $primary->beginTransaction();
        $primary->exec($this->insert(1));
        $this->rejected($other, $review, '55P03');
        $primary->commit();
        $other->exec($review);
        $primary->beginTransaction();
        $primary->exec("UPDATE insurance_plan_versions SET status='published',published_by_user_id=20,published_at=CURRENT_TIMESTAMP,lock_version=lock_version+1 WHERE id=1");
        $this->rejected($other, $this->insert(2), '55P03');
        $primary->commit();
        $this->rejected($other, $this->insert(2), '23514');
        $this->assertSame(1, DB::table('insurance_benefit_rules')->count());
        $this->assertSame('published', DB::table('insurance_plan_versions')->value('status'));
    }

    public function test_concurrent_corrections_have_one_successor_and_do_not_increment_version_on_failure(): void
    {
        $primary = DB::connection()->getPdo();
        $other = $this->other();
        $primary->exec($this->insert(1));
        $primary->beginTransaction();
        $primary->exec($this->insert(2, 1));
        $this->rejected($other, $this->insert(3, 1), '55P03');
        $primary->commit();
        $this->rejected($other, $this->insert(3, 1), '23505');
        $this->assertSame(2, DB::table('insurance_benefit_rules')->count());
        $this->assertSame(3, DB::table('insurance_plan_versions')->value('lock_version'));
    }

    public function test_empty_migration_roundtrip_and_currency_guard_preserve_history(): void
    {
        $migration = require database_path('migrations/2026_11_16_000001_create_insurance_benefit_rules.php');
        $migration->down();
        $this->assertNull(DB::selectOne("SELECT to_regclass('insurance_benefit_rules') AS name")->name);
        $migration->up();
        $primary = DB::connection()->getPdo();
        $primary->exec($this->insert(1));
        $primary->exec("INSERT INTO insurance_benefit_rules(id,tenant_id,plan_version_id,revision,source,scope,coverage,benefit_type,amount,currency,observed_at,provenance,reason,actor_id) VALUES(2,1,1,1,'insurer_plan','all','covered','fixed',10,'JMD','2020-01-01','encrypted','encrypted',10)");
        $this->rejected($this->other(), "UPDATE insurance_plan_versions SET currency='USD',lock_version=lock_version+1 WHERE id=1", '23514');
        $this->assertSame('JMD', DB::table('insurance_plan_versions')->value('currency'));
    }
}
