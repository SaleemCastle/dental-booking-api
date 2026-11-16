<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurance benefit rules require PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE insurance_benefit_rules (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 plan_version_id bigint, association_id bigint, association_revision_id bigint, policy_revision_id bigint,
 supersedes_id bigint UNIQUE, revision integer NOT NULL CHECK(revision>0),
 source varchar(32) NOT NULL CHECK(source IN ('insurer_plan','patient_reported','staff_observation')),
 scope varchar(12) NOT NULL CHECK(scope IN ('all','category','procedure')),
 category varchar(255), procedure_code varchar(100), code_system varchar(100),
 coverage varchar(12) NOT NULL CHECK(coverage IN ('covered','excluded','unknown','unspecified')),
 benefit_type varchar(12) NOT NULL CHECK(benefit_type IN ('percentage','fixed','unknown','unspecified')),
 percentage numeric(5,2) CHECK(percentage BETWEEN 0 AND 100),
 amount numeric(14,2) CHECK(amount>=0), currency varchar(3) CHECK(currency ~ '^[A-Z]{3}$'),
 annual_limit numeric(14,2) CHECK(annual_limit>=0), visit_limit numeric(14,2) CHECK(visit_limit>=0),
 procedure_limit numeric(14,2) CHECK(procedure_limit>=0),
 waiting_period_days integer CHECK(waiting_period_days>=0), prior_authorization_required boolean,
 service_from date, service_to date,
 observed_at timestamp NOT NULL, recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 provenance text NOT NULL, reference text, reason text NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,plan_version_id) REFERENCES insurance_plan_versions(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id) REFERENCES patient_insurer_associations(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,association_revision_id) REFERENCES patient_insurer_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,policy_revision_id) REFERENCES patient_insurance_policy_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,supersedes_id) REFERENCES insurance_benefit_rules(tenant_id,id) ON DELETE RESTRICT,
 CHECK((source='insurer_plan' AND plan_version_id IS NOT NULL AND association_id IS NULL AND association_revision_id IS NULL AND policy_revision_id IS NULL)
    OR (source IN ('patient_reported','staff_observation') AND plan_version_id IS NULL AND association_id IS NOT NULL AND association_revision_id IS NOT NULL)),
 CHECK((scope='all' AND category IS NULL AND procedure_code IS NULL AND code_system IS NULL)
    OR (scope='category' AND length(trim(category))>0 AND category IS NOT NULL AND procedure_code IS NULL AND code_system IS NULL)
    OR (scope='procedure' AND category IS NULL AND procedure_code IS NOT NULL AND length(trim(procedure_code))>0 AND code_system IS NOT NULL AND length(trim(code_system))>0)),
 CHECK((benefit_type='percentage' AND percentage IS NOT NULL AND amount IS NULL)
    OR (benefit_type='fixed' AND amount IS NOT NULL AND percentage IS NULL)
    OR (benefit_type IN ('unknown','unspecified') AND amount IS NULL AND percentage IS NULL)),
 CHECK(coverage='covered' OR benefit_type IN ('unknown','unspecified')),
 CHECK((amount IS NULL AND annual_limit IS NULL AND visit_limit IS NULL AND procedure_limit IS NULL) OR currency IS NOT NULL),
 CHECK(procedure_limit IS NULL OR scope='procedure'),
 CHECK(service_from IS NULL OR service_to IS NULL OR service_from<=service_to),
 CHECK(observed_at<=recorded_at)
);
CREATE INDEX insurance_benefits_plan_browse ON insurance_benefit_rules(tenant_id,plan_version_id,id);
CREATE INDEX insurance_benefits_patient_browse ON insurance_benefit_rules(tenant_id,association_id,id);
CREATE OR REPLACE FUNCTION insurance_benefit_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v insurance_plan_versions; a patient_insurer_associations; old_rule insurance_benefit_rules;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
 THEN RAISE EXCEPTION 'Invalid benefit actor' USING ERRCODE='23514'; END IF;
 IF NEW.plan_version_id IS NOT NULL THEN
  SELECT * INTO v FROM insurance_plan_versions WHERE tenant_id=NEW.tenant_id AND id=NEW.plan_version_id FOR UPDATE;
  IF v.id IS NULL OR v.status<>'draft' OR (NEW.currency IS NOT NULL AND NEW.currency<>v.currency)
  THEN RAISE EXCEPTION 'Benefits require a matching draft plan version' USING ERRCODE='23514'; END IF;
  -- Treat every rule author as a plan editor, preserving independent review/publication.
  UPDATE insurance_plan_versions SET last_edited_by_user_id=NEW.actor_id,lock_version=lock_version+1,updated_at=timezone('UTC',clock_timestamp()) WHERE id=v.id;
 ELSE
  SELECT * INTO a FROM patient_insurer_associations WHERE tenant_id=NEW.tenant_id AND id=NEW.association_id FOR UPDATE;
  IF a.id IS NULL OR NOT EXISTS(SELECT 1 FROM patient_insurer_revisions WHERE tenant_id=NEW.tenant_id AND association_id=a.id AND id=NEW.association_revision_id AND version=a.lock_version)
  THEN RAISE EXCEPTION 'Benefits require current provider snapshot' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.supersedes_id IS NULL THEN
  IF NEW.revision<>1 THEN RAISE EXCEPTION 'Initial benefit revision must be one' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO old_rule FROM insurance_benefit_rules WHERE tenant_id=NEW.tenant_id AND id=NEW.supersedes_id;
  IF old_rule.id IS NULL OR NEW.revision<>old_rule.revision+1
    OR NEW.plan_version_id IS DISTINCT FROM old_rule.plan_version_id OR NEW.association_id IS DISTINCT FROM old_rule.association_id
  THEN RAISE EXCEPTION 'Invalid benefit correction lineage' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_benefit_insert BEFORE INSERT ON insurance_benefit_rules FOR EACH ROW EXECUTE FUNCTION insurance_benefit_insert_guard();
CREATE OR REPLACE FUNCTION insurance_benefit_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Benefit history is immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurance_benefit_no_change BEFORE UPDATE OR DELETE ON insurance_benefit_rules FOR EACH ROW EXECUTE FUNCTION insurance_benefit_immutable();
CREATE TRIGGER insurance_benefit_no_truncate BEFORE TRUNCATE ON insurance_benefit_rules FOR EACH STATEMENT EXECUTE FUNCTION insurance_benefit_immutable();
CREATE OR REPLACE FUNCTION insurance_benefit_currency_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM insurance_benefit_rules WHERE plan_version_id=NEW.id AND currency IS NOT NULL AND currency<>NEW.currency)
 THEN RAISE EXCEPTION 'Plan currency conflicts with recorded benefits' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_benefit_currency BEFORE UPDATE OF currency ON insurance_plan_versions FOR EACH ROW EXECUTE FUNCTION insurance_benefit_currency_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurance_benefit_rules')->exists()) {
            throw new RuntimeException('Preserve benefit history; populated rollback is prohibited.');
        }
        DB::statement('DROP TRIGGER insurance_benefit_currency ON insurance_plan_versions');
        DB::statement('DROP FUNCTION insurance_benefit_currency_guard()');
        DB::statement('DROP TABLE insurance_benefit_rules');
        DB::statement('DROP FUNCTION insurance_benefit_insert_guard()');
        DB::statement('DROP FUNCTION insurance_benefit_immutable()');
    }
};
