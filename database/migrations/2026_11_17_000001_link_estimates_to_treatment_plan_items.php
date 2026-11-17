<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Treatment-linked insurance estimates require PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE treatment_plan_items ADD CONSTRAINT treatment_plan_items_tenant_id_id_unique UNIQUE (tenant_id, id);
ALTER TABLE insurance_estimates ADD COLUMN treatment_plan_item_id bigint NULL;
ALTER TABLE insurance_estimates ADD CONSTRAINT insurance_estimates_treatment_item_tenant_fk
 FOREIGN KEY (tenant_id, treatment_plan_item_id) REFERENCES treatment_plan_items(tenant_id, id) ON DELETE RESTRICT;
CREATE INDEX insurance_estimates_treatment_item_history ON insurance_estimates(tenant_id, treatment_plan_item_id, id DESC)
 WHERE treatment_plan_item_id IS NOT NULL;
CREATE OR REPLACE FUNCTION insurance_estimate_treatment_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.treatment_plan_item_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM treatment_plan_items i JOIN treatment_plans p ON p.id=i.treatment_plan_id
  WHERE i.id=NEW.treatment_plan_item_id AND i.tenant_id=NEW.tenant_id AND p.tenant_id=NEW.tenant_id AND p.patient_id=NEW.patient_id
 ) THEN RAISE EXCEPTION 'Insurance estimate treatment source does not belong to patient' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_estimate_treatment_source_insert BEFORE INSERT ON insurance_estimates
 FOR EACH ROW EXECUTE FUNCTION insurance_estimate_treatment_source_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurance_estimates')->whereNotNull('treatment_plan_item_id')->exists()) {
            throw new RuntimeException('Preserve treatment-linked insurance estimate history; populated rollback is prohibited.');
        }
        DB::statement('DROP TRIGGER insurance_estimate_treatment_source_insert ON insurance_estimates');
        DB::statement('DROP FUNCTION insurance_estimate_treatment_source_guard()');
        DB::statement('ALTER TABLE insurance_estimates DROP CONSTRAINT insurance_estimates_treatment_item_tenant_fk');
        DB::statement('DROP INDEX insurance_estimates_treatment_item_history');
        DB::statement('ALTER TABLE insurance_estimates DROP COLUMN treatment_plan_item_id');
        DB::statement('ALTER TABLE treatment_plan_items DROP CONSTRAINT treatment_plan_items_tenant_id_id_unique');
    }
};
