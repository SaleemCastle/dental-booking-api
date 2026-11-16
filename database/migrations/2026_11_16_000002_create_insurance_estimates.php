<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurance estimates require PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE insurance_estimates (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint NOT NULL, association_id bigint, benefit_rule_id bigint, supersedes_id bigint,
 service_date date NOT NULL, treatment_description varchar(1000) NOT NULL, procedure_code varchar(100), code_system varchar(100),
 gross_amount numeric(14,2) NOT NULL CHECK(gross_amount>=0), currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 status varchar(12) NOT NULL CHECK(status IN ('resolved','unresolved','excluded')),
 insurer_amount numeric(14,2), patient_amount numeric(14,2), calculation jsonb NOT NULL,
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id) REFERENCES patient_insurer_associations(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,benefit_rule_id) REFERENCES insurance_benefit_rules(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,supersedes_id) REFERENCES insurance_estimates(tenant_id,id) ON DELETE RESTRICT,
 CHECK((status='unresolved' AND insurer_amount IS NULL AND patient_amount IS NULL) OR (status<>'unresolved' AND insurer_amount IS NOT NULL AND patient_amount IS NOT NULL)),
 CHECK((association_id IS NOT NULL) OR (benefit_rule_id IS NULL)),
 CHECK(insurer_amount IS NULL OR (insurer_amount>=0 AND patient_amount>=0 AND insurer_amount+patient_amount=gross_amount))
);
CREATE INDEX insurance_estimates_patient_history ON insurance_estimates(tenant_id,patient_id,id DESC);
CREATE UNIQUE INDEX insurance_estimates_one_successor ON insurance_estimates(tenant_id,supersedes_id) WHERE supersedes_id IS NOT NULL;
CREATE OR REPLACE FUNCTION insurance_estimate_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Insurance estimate snapshots are immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurance_estimate_no_change BEFORE UPDATE OR DELETE ON insurance_estimates FOR EACH ROW EXECUTE FUNCTION insurance_estimate_immutable();
CREATE TRIGGER insurance_estimate_no_truncate BEFORE TRUNCATE ON insurance_estimates FOR EACH STATEMENT EXECUTE FUNCTION insurance_estimate_immutable();
CREATE OR REPLACE FUNCTION insurance_estimate_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r insurance_benefit_rules; v insurance_plan_versions; p insurance_plans; a patient_insurer_associations;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
 THEN RAISE EXCEPTION 'Invalid insurance estimate actor' USING ERRCODE='23514'; END IF;
 IF NEW.association_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient_insurer_associations WHERE tenant_id=NEW.tenant_id AND patient_id=NEW.patient_id AND id=NEW.association_id)
 THEN RAISE EXCEPTION 'Insurance estimate association does not belong to patient' USING ERRCODE='23514'; END IF;
 IF NEW.benefit_rule_id IS NOT NULL THEN
  SELECT * INTO r FROM insurance_benefit_rules WHERE tenant_id=NEW.tenant_id AND id=NEW.benefit_rule_id;
  IF r.id IS NULL OR (r.association_id IS NOT NULL AND r.association_id IS DISTINCT FROM NEW.association_id)
  THEN RAISE EXCEPTION 'Invalid insurance estimate benefit source' USING ERRCODE='23514'; END IF;
  IF r.plan_version_id IS NOT NULL THEN
   SELECT * INTO v FROM insurance_plan_versions WHERE tenant_id=NEW.tenant_id AND id=r.plan_version_id;
   SELECT * INTO p FROM insurance_plans WHERE tenant_id=NEW.tenant_id AND id=v.insurance_plan_id;
   IF v.status<>'published' OR NEW.service_date<v.effective_from OR (v.effective_to IS NOT NULL AND NEW.service_date>v.effective_to)
   THEN RAISE EXCEPTION 'Insurance estimate plan version is not applicable' USING ERRCODE='23514'; END IF;
   IF NEW.association_id IS NOT NULL THEN
    SELECT * INTO a FROM patient_insurer_associations WHERE tenant_id=NEW.tenant_id AND id=NEW.association_id;
    IF a.insurer_id IS NOT NULL AND a.insurer_id IS DISTINCT FROM p.insurer_id
    THEN RAISE EXCEPTION 'Insurance estimate provider and plan conflict' USING ERRCODE='23514'; END IF;
   END IF;
  END IF;
  IF (r.service_from IS NOT NULL AND NEW.service_date<r.service_from) OR (r.service_to IS NOT NULL AND NEW.service_date>r.service_to)
  THEN RAISE EXCEPTION 'Insurance estimate rule is not applicable on service date' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_estimate_insert BEFORE INSERT ON insurance_estimates FOR EACH ROW EXECUTE FUNCTION insurance_estimate_insert_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurance_estimates')->exists()) {
            throw new RuntimeException('Preserve insurance estimate history; populated rollback is prohibited.');
        }
        DB::statement('DROP TRIGGER insurance_estimate_insert ON insurance_estimates');
        DB::statement('DROP TRIGGER insurance_estimate_no_truncate ON insurance_estimates');
        DB::statement('DROP TRIGGER insurance_estimate_no_change ON insurance_estimates');
        DB::statement('DROP TABLE insurance_estimates');
        DB::statement('DROP FUNCTION insurance_estimate_insert_guard()');
        DB::statement('DROP FUNCTION insurance_estimate_immutable()');
    }
};
