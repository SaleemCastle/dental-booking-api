<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurance claims require PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE patient_insurer_associations
 ADD CONSTRAINT patient_insurer_associations_tenant_id_patient_unique UNIQUE(tenant_id,id,patient_id);

CREATE TABLE insurance_claims (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint NOT NULL,
 association_id bigint NOT NULL,
 policy_revision_id bigint,
 estimate_id bigint,
 corrects_claim_id bigint,
 claim_identifier uuid NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','ready','submitted','cancelled')),
 lock_version integer NOT NULL DEFAULT 1 CHECK(lock_version>0),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 updated_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 updated_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,claim_identifier),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,patient_id) REFERENCES patient_insurer_associations(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,policy_revision_id) REFERENCES patient_insurance_policy_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,estimate_id) REFERENCES insurance_estimates(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,corrects_claim_id) REFERENCES insurance_claims(tenant_id,id) ON DELETE RESTRICT
);

CREATE TABLE insurance_claim_lines (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 claim_id bigint NOT NULL,
 treatment_plan_item_id bigint,
 treatment_description varchar(1000) NOT NULL CHECK(length(trim(treatment_description))>0),
 procedure_code varchar(100),
 code_system varchar(100),
 service_date date NOT NULL,
 quantity integer NOT NULL CHECK(quantity BETWEEN 1 AND 10000),
 unit_amount numeric(14,2) NOT NULL CHECK(unit_amount>=0),
 gross_amount numeric(14,2) GENERATED ALWAYS AS (unit_amount * quantity) STORED,
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 created_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 updated_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,claim_id) REFERENCES insurance_claims(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,treatment_plan_item_id) REFERENCES treatment_plan_items(tenant_id,id) ON DELETE RESTRICT,
 CHECK((procedure_code IS NULL AND code_system IS NULL) OR (procedure_code IS NOT NULL AND length(trim(procedure_code))>0 AND code_system IS NOT NULL AND length(trim(code_system))>0))
);

CREATE TABLE insurance_claim_submissions (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 claim_id bigint NOT NULL,
 sequence integer NOT NULL CHECK(sequence>0),
 idempotency_key varchar(120) NOT NULL CHECK(length(trim(idempotency_key))>0),
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 channel varchar(16) NOT NULL CHECK(channel IN ('portal','email','paper','other')),
 channel_metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK(jsonb_typeof(channel_metadata)='object'),
 claim_version integer NOT NULL CHECK(claim_version>0),
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object'),
 submitted_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 submitted_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,claim_id,sequence), UNIQUE(tenant_id,claim_id,idempotency_key),
 FOREIGN KEY(tenant_id,claim_id) REFERENCES insurance_claims(tenant_id,id) ON DELETE RESTRICT
);

CREATE TABLE insurance_claim_events (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 claim_id bigint NOT NULL,
 version integer NOT NULL CHECK(version>0),
 from_status varchar(16) CHECK(from_status IS NULL OR from_status IN ('draft','ready','submitted','cancelled')),
 to_status varchar(16) NOT NULL CHECK(to_status IN ('draft','ready','submitted','cancelled')),
 event_type varchar(24) NOT NULL CHECK(event_type IN ('created','validated','draft_updated','cancelled','submitted')),
 reason text,
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 occurred_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,claim_id,version),
 FOREIGN KEY(tenant_id,claim_id) REFERENCES insurance_claims(tenant_id,id) ON DELETE RESTRICT
);

CREATE INDEX insurance_claims_patient_history ON insurance_claims(tenant_id,patient_id,id DESC);
CREATE INDEX insurance_claim_lines_claim_order ON insurance_claim_lines(tenant_id,claim_id,id);
CREATE INDEX insurance_claim_submissions_history ON insurance_claim_submissions(tenant_id,claim_id,sequence DESC);
CREATE INDEX insurance_claim_events_history ON insurance_claim_events(tenant_id,claim_id,version);

CREATE OR REPLACE FUNCTION insurance_claim_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' OR NEW.lock_version<>1 OR NEW.created_by_user_id<>NEW.updated_by_user_id
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id
       AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id
       AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.corrects_claim_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.corrects_claim_id
       AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id AND c.status='submitted'))
  THEN RAISE EXCEPTION 'Invalid insurance claim creation or source lineage' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.association_id,NEW.created_by_user_id,NEW.created_at,NEW.claim_identifier,NEW.corrects_claim_id)
     IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.association_id,OLD.created_by_user_id,OLD.created_at,OLD.claim_identifier,OLD.corrects_claim_id)
   OR OLD.status IN ('submitted','cancelled') OR NEW.lock_version<>OLD.lock_version+1
   OR NEW.updated_by_user_id IS NULL
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.updated_by_user_id)
   OR NOT ((OLD.status='draft' AND NEW.status IN ('draft','ready','cancelled'))
        OR (OLD.status='ready' AND NEW.status IN ('draft','ready','submitted','cancelled')))
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.status='ready' AND (NOT EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)
       OR EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id AND l.gross_amount<=0)
       OR (SELECT count(DISTINCT currency) FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)>1))
  THEN RAISE EXCEPTION 'Invalid insurance claim update or lifecycle transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_guard_trigger BEFORE INSERT OR UPDATE ON insurance_claims FOR EACH ROW EXECUTE FUNCTION insurance_claim_guard();

CREATE OR REPLACE FUNCTION insurance_claim_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c insurance_claims;
BEGIN
 SELECT * INTO c FROM insurance_claims WHERE tenant_id=COALESCE(NEW.tenant_id,OLD.tenant_id) AND id=COALESCE(NEW.claim_id,OLD.claim_id);
 IF c.id IS NULL OR c.status<>'draft' THEN RAISE EXCEPTION 'Claim lines can only change while claim is draft' USING ERRCODE='23514'; END IF;
 IF TG_OP<>'DELETE' AND NEW.treatment_plan_item_id IS NOT NULL AND NOT EXISTS(
    SELECT 1 FROM treatment_plan_items i JOIN treatment_plans p ON p.tenant_id=i.tenant_id AND p.id=i.treatment_plan_id
     WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.treatment_plan_item_id AND p.patient_id=c.patient_id)
 THEN RAISE EXCEPTION 'Claim line treatment source does not belong to claim patient' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (NEW.id<>OLD.id OR NEW.tenant_id<>OLD.tenant_id OR NEW.claim_id<>OLD.claim_id OR NEW.created_at<>OLD.created_at)
 THEN RAISE EXCEPTION 'Claim line ownership is immutable' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON insurance_claim_lines FOR EACH ROW EXECUTE FUNCTION insurance_claim_line_guard();

CREATE OR REPLACE FUNCTION insurance_claim_history_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Insurance claim history is immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurance_claim_submissions_immutable BEFORE UPDATE OR DELETE ON insurance_claim_submissions FOR EACH ROW EXECUTE FUNCTION insurance_claim_history_immutable();
CREATE TRIGGER insurance_claim_submissions_no_truncate BEFORE TRUNCATE ON insurance_claim_submissions FOR EACH STATEMENT EXECUTE FUNCTION insurance_claim_history_immutable();
CREATE TRIGGER insurance_claim_events_immutable BEFORE UPDATE OR DELETE ON insurance_claim_events FOR EACH ROW EXECUTE FUNCTION insurance_claim_history_immutable();
CREATE TRIGGER insurance_claim_events_no_truncate BEFORE TRUNCATE ON insurance_claim_events FOR EACH STATEMENT EXECUTE FUNCTION insurance_claim_history_immutable();
CREATE TRIGGER insurance_claim_lines_no_truncate BEFORE TRUNCATE ON insurance_claim_lines FOR EACH STATEMENT EXECUTE FUNCTION insurance_claim_history_immutable();

CREATE OR REPLACE FUNCTION insurance_claim_submission_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c insurance_claims; last_sequence integer; expected_lines jsonb; expected_snapshot jsonb;
BEGIN
 SELECT * INTO c FROM insurance_claims WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id FOR UPDATE;
 SELECT COALESCE(MAX(sequence),0) INTO last_sequence FROM insurance_claim_submissions WHERE tenant_id=NEW.tenant_id AND claim_id=NEW.claim_id;
 SELECT COALESCE(jsonb_agg(jsonb_build_object(
   'treatment_plan_item_id',l.treatment_plan_item_id,'treatment_description',l.treatment_description,
   'procedure_code',l.procedure_code,'code_system',l.code_system,'service_date',l.service_date::text,
   'quantity',l.quantity,'unit_amount',l.unit_amount::text,'gross_amount',l.gross_amount::text,'currency',l.currency
 ) ORDER BY l.id),'[]'::jsonb) INTO expected_lines FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.claim_id;
 expected_snapshot=jsonb_build_object('claim_identifier',c.claim_identifier,'patient_id',c.patient_id,
   'association_id',c.association_id,'policy_revision_id',c.policy_revision_id,'estimate_id',c.estimate_id,
   'prepared_claim_version',c.lock_version,'lines',expected_lines);
 IF c.id IS NULL OR c.status<>'ready' OR NEW.sequence<>last_sequence+1 OR NEW.claim_version<>c.lock_version
  OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.submitted_by_user_id)
  OR jsonb_array_length(COALESCE(NEW.snapshot->'lines','[]'::jsonb))<1 OR NEW.snapshot IS DISTINCT FROM expected_snapshot
 THEN RAISE EXCEPTION 'Invalid insurance claim submission snapshot' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_submission_insert_guard BEFORE INSERT ON insurance_claim_submissions FOR EACH ROW EXECUTE FUNCTION insurance_claim_submission_guard();

CREATE OR REPLACE FUNCTION insurance_claim_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c insurance_claims;
BEGIN
 SELECT * INTO c FROM insurance_claims WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id;
 IF c.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
  OR NEW.version<>c.lock_version OR NEW.to_status<>c.status
  OR (NEW.event_type='created' AND (NEW.version<>1 OR NEW.from_status IS NOT NULL OR NEW.to_status<>'draft'))
  OR (NEW.event_type='validated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='ready'))
  OR (NEW.event_type='draft_updated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='draft'))
  OR (NEW.event_type='cancelled' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='cancelled'))
  OR (NEW.event_type='submitted' AND NOT (NEW.from_status='ready' AND NEW.to_status='submitted'))
 THEN RAISE EXCEPTION 'Invalid insurance claim lifecycle event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_event_insert_guard BEFORE INSERT ON insurance_claim_events FOR EACH ROW EXECUTE FUNCTION insurance_claim_event_guard();

CREATE OR REPLACE FUNCTION insurance_claim_require_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM insurance_claim_events e WHERE e.tenant_id=NEW.tenant_id AND e.claim_id=NEW.id AND e.version=NEW.lock_version)
 THEN RAISE EXCEPTION 'Insurance claim lifecycle event required for each version' USING ERRCODE='23514'; END IF;
 IF NEW.status='submitted' AND NOT EXISTS(SELECT 1 FROM insurance_claim_submissions s WHERE s.tenant_id=NEW.tenant_id AND s.claim_id=NEW.id AND s.claim_version=NEW.lock_version-1)
 THEN RAISE EXCEPTION 'Submitted claims require immutable submission snapshot' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER insurance_claim_require_history_trigger AFTER INSERT OR UPDATE ON insurance_claims DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION insurance_claim_require_history();
SQL);
    }

    public function down(): void
    {
        foreach (['insurance_claims', 'insurance_claim_lines', 'insurance_claim_submissions', 'insurance_claim_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve insurance claim and submission history; populated rollback is prohibited.');
            }
        }
        foreach (['insurance_claim_require_history_trigger' => 'insurance_claims', 'insurance_claim_event_insert_guard' => 'insurance_claim_events',
            'insurance_claim_submission_insert_guard' => 'insurance_claim_submissions', 'insurance_claim_events_no_truncate' => 'insurance_claim_events',
            'insurance_claim_events_immutable' => 'insurance_claim_events', 'insurance_claim_submissions_no_truncate' => 'insurance_claim_submissions',
            'insurance_claim_submissions_immutable' => 'insurance_claim_submissions', 'insurance_claim_lines_no_truncate' => 'insurance_claim_lines',
            'insurance_claim_lines_guard' => 'insurance_claim_lines',
            'insurance_claim_guard_trigger' => 'insurance_claims'] as $trigger => $table) {
            DB::statement("DROP TRIGGER $trigger ON $table");
        }
        foreach (['insurance_claim_require_history', 'insurance_claim_event_guard', 'insurance_claim_submission_guard',
            'insurance_claim_history_immutable', 'insurance_claim_line_guard', 'insurance_claim_guard'] as $function) {
            DB::statement("DROP FUNCTION $function()");
        }
        DB::statement('DROP TABLE insurance_claim_events');
        DB::statement('DROP TABLE insurance_claim_submissions');
        DB::statement('DROP TABLE insurance_claim_lines');
        DB::statement('DROP TABLE insurance_claims');
        DB::statement('ALTER TABLE patient_insurer_associations DROP CONSTRAINT patient_insurer_associations_tenant_id_patient_unique');
    }
};
