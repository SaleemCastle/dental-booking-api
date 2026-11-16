<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = ['patient_insurer_associations', 'patient_insurer_revisions', 'patient_insurance_policies', 'patient_insurance_policy_revisions', 'patient_insurance_checks'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Patient insurance requires PostgreSQL.');
        }
        // Reuse the evidence migration's candidate key when it is already installed.
        if (! DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='patient'::regclass AND contype='u' AND pg_get_constraintdef(oid)='UNIQUE (tenant_id, id)') AS found")->found) {
            DB::statement('ALTER TABLE patient ADD CONSTRAINT patient_insurance_patient_tenant_unique UNIQUE(tenant_id,id)');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE patient_insurer_associations (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint NOT NULL, insurer_id bigint, unverified_insurer_name varchar(255),
 coverage_priority integer CHECK(coverage_priority>0), active boolean NOT NULL DEFAULT true,
 lock_version integer NOT NULL DEFAULT 1 CHECK(lock_version>0),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT,
 CHECK((insurer_id IS NOT NULL AND unverified_insurer_name IS NULL) OR (insurer_id IS NULL AND unverified_insurer_name IS NOT NULL AND length(trim(unverified_insurer_name))>0))
);
CREATE TABLE patient_insurer_revisions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, association_id bigint NOT NULL, version integer NOT NULL CHECK(version>0),
 insurer_id bigint, unverified_insurer_name varchar(255), coverage_priority integer CHECK(coverage_priority>0), active boolean NOT NULL,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,association_id,id), UNIQUE(association_id,version),
 FOREIGN KEY(tenant_id,association_id) REFERENCES patient_insurer_associations(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE patient_insurance_policies (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, association_id bigint NOT NULL UNIQUE,
 lock_version integer NOT NULL DEFAULT 1 CHECK(lock_version>0),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE(tenant_id,association_id,id),
 FOREIGN KEY(tenant_id,association_id) REFERENCES patient_insurer_associations(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE patient_insurance_policy_revisions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, association_id bigint NOT NULL, policy_id bigint NOT NULL,
 association_revision_id bigint NOT NULL, version integer NOT NULL CHECK(version>0),
 policy_number text, membership_id text, subscriber_name text, relationship varchar(10), plan_id bigint,
 effective_date date, expiry_date date, reason text NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,association_id,id), UNIQUE(policy_id,version),
 FOREIGN KEY(tenant_id,association_id,policy_id) REFERENCES patient_insurance_policies(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,association_revision_id) REFERENCES patient_insurer_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,plan_id) REFERENCES insurance_plans(tenant_id,id) ON DELETE RESTRICT,
 CHECK(relationship IS NULL OR relationship IN ('self','spouse','child','other')),
 CHECK(effective_date IS NULL OR expiry_date IS NULL OR effective_date<=expiry_date)
);
CREATE TABLE patient_insurance_checks (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, association_id bigint NOT NULL, association_revision_id bigint NOT NULL,
 policy_revision_id bigint, outcome varchar(20) NOT NULL, channel varchar(20) NOT NULL,
 checked_at timestamp NOT NULL, service_date date, provenance text NOT NULL, reference text,
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 FOREIGN KEY(tenant_id,association_id,association_revision_id) REFERENCES patient_insurer_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,policy_revision_id) REFERENCES patient_insurance_policy_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 CHECK(outcome IN ('unknown','active','inactive','unable_to_verify')),
 CHECK(channel IN ('external_card','external_portal','telephone','paper','other')),
 CHECK(checked_at<=recorded_at)
);
CREATE INDEX patient_insurer_patient_browse ON patient_insurer_associations(tenant_id,patient_id,id);
CREATE INDEX patient_insurance_checks_browse ON patient_insurance_checks(tenant_id,association_id,id);
CREATE OR REPLACE FUNCTION patient_insurance_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Patient insurance history is immutable' USING ERRCODE='23514'; END; $$;
CREATE OR REPLACE FUNCTION patient_insurance_parent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.lock_version<>1 OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
  THEN RAISE EXCEPTION 'Invalid patient insurance creator or version' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.created_by_user_id,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.created_by_user_id,OLD.created_at)
   OR NEW.lock_version<>OLD.lock_version+1
  THEN RAISE EXCEPTION 'Patient insurance identity/version is immutable' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='patient_insurer_associations' THEN
   IF NEW.patient_id IS DISTINCT FROM OLD.patient_id THEN RAISE EXCEPTION 'Patient insurance owner is immutable' USING ERRCODE='23514'; END IF;
  ELSE
   IF NEW.association_id IS DISTINCT FROM OLD.association_id THEN RAISE EXCEPTION 'Policy owner is immutable' USING ERRCODE='23514'; END IF;
  END IF;
 END IF; RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION patient_insurance_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a patient_insurer_associations; ar patient_insurer_revisions; p patient_insurance_policies;
BEGIN
 SELECT * INTO a FROM patient_insurer_associations WHERE id=NEW.association_id AND tenant_id=NEW.tenant_id FOR UPDATE;
 IF a.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
 THEN RAISE EXCEPTION 'Invalid patient insurance owner or actor' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='patient_insurer_revisions' THEN
  IF NEW.version<>a.lock_version OR ROW(NEW.insurer_id,NEW.unverified_insurer_name,NEW.coverage_priority,NEW.active)
   IS DISTINCT FROM ROW(a.insurer_id,a.unverified_insurer_name,a.coverage_priority,a.active)
  THEN RAISE EXCEPTION 'Association revision must snapshot current state' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO ar FROM patient_insurer_revisions WHERE id=NEW.association_revision_id AND tenant_id=NEW.tenant_id AND association_id=a.id;
  IF ar.id IS NULL OR ar.version<>a.lock_version THEN RAISE EXCEPTION 'Missing current provider snapshot' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='patient_insurance_policy_revisions' THEN
   SELECT * INTO p FROM patient_insurance_policies WHERE id=NEW.policy_id AND association_id=a.id AND tenant_id=NEW.tenant_id FOR UPDATE;
   IF p.id IS NULL OR NEW.version<>p.lock_version OR
    (NEW.plan_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_plans WHERE id=NEW.plan_id AND tenant_id=NEW.tenant_id AND insurer_id=ar.insurer_id))
   THEN RAISE EXCEPTION 'Invalid policy version or insurer plan' USING ERRCODE='23514'; END IF;
  END IF;
 END IF; RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION patient_insurance_require_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='patient_insurer_associations' THEN
  IF NOT EXISTS(SELECT 1 FROM patient_insurer_revisions WHERE association_id=NEW.id AND tenant_id=NEW.tenant_id AND version=NEW.lock_version)
  THEN RAISE EXCEPTION 'Association revision required' USING ERRCODE='23514'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM patient_insurance_policy_revisions WHERE policy_id=NEW.id AND tenant_id=NEW.tenant_id AND version=NEW.lock_version)
  THEN RAISE EXCEPTION 'Policy revision required' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END; $$;
SQL);
        foreach (['patient_insurer_associations', 'patient_insurance_policies'] as $table) {
            DB::unprepared("CREATE TRIGGER patient_insurance_parent_insert BEFORE INSERT OR UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION patient_insurance_parent_guard()");
            DB::unprepared("CREATE CONSTRAINT TRIGGER patient_insurance_parent_history AFTER INSERT OR UPDATE ON $table DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION patient_insurance_require_revision()");
        }
        foreach (['patient_insurer_revisions', 'patient_insurance_policy_revisions', 'patient_insurance_checks'] as $table) {
            DB::unprepared("CREATE TRIGGER patient_insurance_revision_insert BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION patient_insurance_revision_guard()");
            DB::unprepared("CREATE TRIGGER patient_insurance_history_update BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION patient_insurance_immutable()");
        }
        foreach ($this->tables as $table) {
            DB::unprepared("CREATE TRIGGER patient_insurance_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION patient_insurance_immutable()");
            DB::unprepared("CREATE TRIGGER patient_insurance_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION patient_insurance_immutable()");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve patient insurance history; populated rollback is prohibited.');
            }
        }
        foreach (array_reverse($this->tables) as $table) {
            DB::statement("DROP TABLE $table");
        }
        foreach (['patient_insurance_require_revision', 'patient_insurance_revision_guard', 'patient_insurance_parent_guard', 'patient_insurance_immutable'] as $function) {
            DB::statement("DROP FUNCTION $function()");
        }
        DB::statement('ALTER TABLE patient DROP CONSTRAINT IF EXISTS patient_insurance_patient_tenant_unique');
    }
};
