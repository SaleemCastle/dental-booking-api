<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['insurers', 'insurance_plans', 'insurance_plan_versions', 'insurance_version_editors'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Insurance catalog requires SQLite, MySQL or PostgreSQL.');
        }

        Schema::create('insurers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active', 'id']);
        });
        Schema::create('insurance_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('insurer_id');
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'insurer_id', 'code'], 'insurance_plans_code_unique');
            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->onDelete('restrict')->onUpdate('restrict');
            $table->index(['tenant_id', 'insurer_id', 'active', 'id'], 'insurance_plans_browse');
        });
        Schema::create('insurance_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('insurance_plan_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->string('name');
            $table->string('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_reference', 255)->nullable();
            // Historical actors survive membership removal. Validate membership on attribution only.
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('last_edited_by_user_id');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'insurance_plan_id', 'version'], 'insurance_plan_versions_number_unique');
            $table->foreign(['tenant_id', 'insurance_plan_id'], 'insurance_versions_plan_fk')->references(['tenant_id', 'id'])->on('insurance_plans')->onDelete('restrict')->onUpdate('restrict');
            $table->index(['tenant_id', 'insurance_plan_id', 'status', 'effective_from'], 'insurance_versions_browse');
        });
        Schema::create('insurance_version_editors', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('insurance_plan_version_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['tenant_id', 'insurance_plan_version_id', 'user_id'], 'insurance_version_editors_pk');
            $table->foreign(['tenant_id', 'insurance_plan_version_id'], 'insurance_editors_version_fk')->references(['tenant_id', 'id'])->on('insurance_plan_versions')->onDelete('restrict')->onUpdate('restrict');
        });

        foreach (['insurers', 'insurance_plans'] as $table) {
            $parentCheck = $table === 'insurance_plans'
                ? ' OR NOT EXISTS (SELECT 1 FROM insurers p WHERE p.id = NEW.insurer_id AND p.tenant_id = NEW.tenant_id)' : '';
            $codeCheck = match (DB::getDriverName()) {
                'pgsql' => "NEW.code !~ '^[A-Z0-9][A-Z0-9_-]{0,49}$'",
                'mysql' => "NOT REGEXP_LIKE(NEW.code, '^[A-Z0-9][A-Z0-9_-]{0,49}$', 'c')",
                default => "NEW.code GLOB '*[^A-Z0-9_-]*' OR SUBSTR(NEW.code, 1, 1) NOT GLOB '[A-Z0-9]'",
            };
            $base = "NEW.tenant_id IS NULL OR NEW.lock_version < 1 OR LENGTH(TRIM(NEW.name)) < 1 OR LENGTH(NEW.name) > 255 OR LENGTH(NEW.code) NOT BETWEEN 1 AND 50 OR ($codeCheck)$parentCheck";
            $this->guard($table, 'insert', $base.' OR NEW.lock_version <> 1');
            $identity = 'NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.code <> OLD.code OR '.$this->changed('created_at');
            if ($table === 'insurance_plans') {
                $identity .= ' OR NEW.insurer_id <> OLD.insurer_id';
            }
            $this->guard($table, 'update', "$base OR $identity OR NEW.lock_version <> OLD.lock_version + 1");
        }

        $currencyCheck = match (DB::getDriverName()) {
            'pgsql' => "NEW.currency !~ '^[A-Z]{3}$'",
            'mysql' => "NOT REGEXP_LIKE(NEW.currency, '^[A-Z]{3}$', 'c')",
            default => "NEW.currency GLOB '*[^A-Z]*'",
        };
        $base = "NEW.tenant_id IS NULL OR NEW.version < 1 OR NEW.lock_version < 1
            OR NEW.status NOT IN ('draft', 'reviewed', 'published', 'withdrawn')
            OR LENGTH(TRIM(NEW.name)) < 1 OR LENGTH(NEW.name) > 255
            OR LENGTH(NEW.currency) <> 3 OR ($currencyCheck)
            OR NEW.effective_from IS NULL OR (NEW.effective_to IS NOT NULL AND NEW.effective_to < NEW.effective_from)
            OR NOT EXISTS (SELECT 1 FROM insurance_plans p WHERE p.id = NEW.insurance_plan_id AND p.tenant_id = NEW.tenant_id)
            OR (NEW.status = 'draft' AND (NEW.reviewed_by_user_id IS NOT NULL OR NEW.reviewed_at IS NOT NULL))
            OR (NEW.status IN ('reviewed', 'published') AND (NEW.reviewed_by_user_id IS NULL OR NEW.reviewed_at IS NULL))
            OR (NEW.status <> 'published' AND (NEW.published_by_user_id IS NOT NULL OR NEW.published_at IS NOT NULL))
            OR (NEW.status = 'published' AND (NEW.published_by_user_id IS NULL OR NEW.published_at IS NULL))";
        foreach (['insert', 'update'] as $event) {
            $condition = $base;
            foreach (['created_by_user_id', 'last_edited_by_user_id', 'reviewed_by_user_id', 'published_by_user_id'] as $column) {
                $changed = $event === 'insert' ? '1 = 1' : "(OLD.$column IS NULL OR NEW.$column <> OLD.$column)";
                $condition .= " OR (NEW.$column IS NOT NULL AND $changed AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.$column))";
            }
            if ($event === 'insert') {
                $condition .= " OR NEW.status <> 'draft' OR NEW.lock_version <> 1 OR NEW.last_edited_by_user_id <> NEW.created_by_user_id";
            } else {
                $condition .= " OR OLD.status IN ('published', 'withdrawn') OR NEW.lock_version <> OLD.lock_version + 1
                    OR NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.insurance_plan_id <> OLD.insurance_plan_id
                    OR NEW.version <> OLD.version OR NEW.created_by_user_id <> OLD.created_by_user_id OR ".$this->changed('created_at')."
                    OR (OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'reviewed', 'withdrawn'))
                    OR (OLD.status = 'reviewed' AND NEW.status NOT IN ('published', 'withdrawn'))";
                foreach (['name', 'currency', 'effective_from', 'effective_to', 'source_reference', 'last_edited_by_user_id'] as $column) {
                    $condition .= " OR ((OLD.status <> 'draft' OR NEW.status <> 'draft') AND ".$this->changed($column).')';
                }
                foreach (['reviewed_by_user_id', 'reviewed_at'] as $column) {
                    $condition .= " OR (OLD.status = 'reviewed' AND ".$this->changed($column).')';
                }
                $condition .= " OR (NEW.status IN ('reviewed', 'published') AND EXISTS (SELECT 1 FROM insurance_version_editors e WHERE e.insurance_plan_version_id = NEW.id AND e.tenant_id = NEW.tenant_id AND (e.user_id = NEW.reviewed_by_user_id OR e.user_id = NEW.published_by_user_id)))
                    OR (NEW.status = 'published' AND NOT EXISTS (SELECT 1 FROM insurance_plans p JOIN insurers i ON i.id = p.insurer_id AND i.tenant_id = p.tenant_id WHERE p.id = NEW.insurance_plan_id AND p.tenant_id = NEW.tenant_id AND p.active = ".$this->trueValue().' AND i.active = '.$this->trueValue().'))';
                if (DB::getDriverName() !== 'pgsql') {
                    $condition .= " OR (NEW.status = 'published' AND EXISTS (SELECT 1 FROM insurance_plan_versions v WHERE v.insurance_plan_id = NEW.insurance_plan_id AND v.tenant_id = NEW.tenant_id AND v.id <> NEW.id AND v.status = 'published' AND (NEW.effective_to IS NULL OR v.effective_from <= NEW.effective_to) AND (v.effective_to IS NULL OR v.effective_to >= NEW.effective_from)))";
                }
            }
            $this->guard('insurance_plan_versions', $event, $condition);
        }
        $this->guard('insurance_version_editors', 'insert', 'NOT EXISTS (SELECT 1 FROM insurance_plan_versions v WHERE v.tenant_id = NEW.tenant_id AND v.id = NEW.insurance_plan_version_id AND v.status = \'draft\' AND v.last_edited_by_user_id = NEW.user_id)');
        $this->guard('insurance_version_editors', 'update', '1 = 1');

        foreach ($this->tables as $table) {
            $this->guard($table, 'delete', '1 = 1');
        }
        $this->editorTriggers();
        if (DB::getDriverName() === 'pgsql') {
            // Built-in numeric/date range opclasses: no btree_gist extension or superuser privilege.
            DB::unprepared("ALTER TABLE insurance_plan_versions ADD CONSTRAINT insurance_versions_no_overlap EXCLUDE USING gist (numrange(insurance_plan_id::numeric, insurance_plan_id::numeric, '[]') WITH &&, daterange(effective_from, effective_to, '[]') WITH &&) WHERE (status = 'published')");
            // migrate:fresh drops tables/triggers but retains these schema-owned functions.
            DB::unprepared("CREATE OR REPLACE FUNCTION insurance_catalog_no_truncate() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN RAISE EXCEPTION 'Insurance catalog history cannot be truncated' USING ERRCODE = '23514'; END; \$body\$");
            foreach ($this->tables as $table) {
                DB::unprepared("CREATE TRIGGER insurance_catalog_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION insurance_catalog_no_truncate()");
            }
        }
    }

    private function editorTriggers(): void
    {
        $values = '(tenant_id, insurance_plan_version_id, user_id) VALUES (NEW.tenant_id, NEW.id, NEW.last_edited_by_user_id)';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION insurance_catalog_track_editor() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN IF NEW.status = 'draft' THEN INSERT INTO insurance_version_editors $values ON CONFLICT DO NOTHING; END IF; RETURN NEW; END; \$body\$");
            DB::unprepared('CREATE TRIGGER insurance_catalog_track_editor AFTER INSERT OR UPDATE ON insurance_plan_versions FOR EACH ROW EXECUTE FUNCTION insurance_catalog_track_editor()');
        } else {
            foreach (['insert', 'update'] as $event) {
                $sql = DB::getDriverName() === 'sqlite'
                    ? "CREATE TRIGGER insurance_track_editor_$event AFTER $event ON insurance_plan_versions FOR EACH ROW WHEN NEW.status = 'draft' BEGIN INSERT OR IGNORE INTO insurance_version_editors $values; END"
                    : "CREATE TRIGGER insurance_track_editor_$event AFTER $event ON insurance_plan_versions FOR EACH ROW BEGIN IF NEW.status = 'draft' THEN INSERT IGNORE INTO insurance_version_editors $values; END IF; END";
                DB::unprepared($sql);
            }
        }
    }

    private function guard(string $table, string $event, string $condition): void
    {
        MigrationGuard::create('ic_'.$table.'_'.$event, $table, $event, $condition, 'Insurance catalog ownership, version or history violation');
    }

    private function changed(string $column): string
    {
        return "(NEW.$column <> OLD.$column OR (NEW.$column IS NULL AND OLD.$column IS NOT NULL) OR (NEW.$column IS NOT NULL AND OLD.$column IS NULL))";
    }

    private function trueValue(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'TRUE' : '1';
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve insurance catalog history; populated rollback requires explicit recovery.');
            }
        }
        foreach (array_reverse($this->tables) as $table) {
            foreach (['insert', 'update', 'delete'] as $event) {
                MigrationGuard::drop('ic_'.$table.'_'.$event, $table);
            }
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS insurance_catalog_track_editor()');
            DB::unprepared('DROP FUNCTION IF EXISTS insurance_catalog_no_truncate()');
        }
    }
};
