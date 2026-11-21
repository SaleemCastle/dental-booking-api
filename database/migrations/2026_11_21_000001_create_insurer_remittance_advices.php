<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurer remittance advice requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE insurance_remittance_advices (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 insurer_id bigint NOT NULL,
 external_reference varchar(200) NOT NULL CHECK(length(trim(external_reference)) BETWEEN 1 AND 200),
 advice_date date NOT NULL,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 communication_channel varchar(16) NOT NULL CHECK(communication_channel IN ('portal','email','fax','paper','phone','other')),
 source_provenance varchar(2000) NOT NULL CHECK(length(trim(source_provenance))>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 reported_billed_total numeric(14,2) CHECK(reported_billed_total IS NULL OR reported_billed_total>=0),
 reported_allowed_total numeric(14,2) CHECK(reported_allowed_total IS NULL OR reported_allowed_total>=0),
 reported_paid_total numeric(14,2) CHECK(reported_paid_total IS NULL OR reported_paid_total>=0),
 reported_denied_total numeric(14,2) CHECK(reported_denied_total IS NULL OR reported_denied_total>=0),
 reported_adjusted_total numeric(14,2),
 corrects_advice_id bigint,
 correction_reason varchar(2000),
 command_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,corrects_advice_id) REFERENCES insurance_remittance_advices(tenant_id,id) ON DELETE RESTRICT,
 CHECK((corrects_advice_id IS NULL AND correction_reason IS NULL) OR (corrects_advice_id IS NOT NULL AND length(trim(correction_reason))>0)),
 CHECK(corrects_advice_id IS NULL OR corrects_advice_id<>id)
);
CREATE UNIQUE INDEX insurance_remittance_original_reference_unique
 ON insurance_remittance_advices(tenant_id,insurer_id,external_reference)
 WHERE corrects_advice_id IS NULL;
CREATE UNIQUE INDEX insurance_remittance_one_correction_unique
 ON insurance_remittance_advices(tenant_id,corrects_advice_id)
 WHERE corrects_advice_id IS NOT NULL;
CREATE INDEX insurance_remittance_advice_history
 ON insurance_remittance_advices(tenant_id,insurer_id,advice_date DESC,id DESC);

CREATE TABLE insurance_remittance_lines (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 advice_id bigint NOT NULL,
 line_number integer NOT NULL CHECK(line_number>0),
 external_line_reference varchar(200),
 patient_id bigint NOT NULL,
 association_id bigint NOT NULL,
 claim_id bigint,
 submission_id bigint,
 supporting_evidence_id bigint,
 billed_amount numeric(14,2) CHECK(billed_amount IS NULL OR billed_amount>=0),
 allowed_amount numeric(14,2) CHECK(allowed_amount IS NULL OR allowed_amount>=0),
 paid_amount numeric(14,2) CHECK(paid_amount IS NULL OR paid_amount>=0),
 denied_amount numeric(14,2) CHECK(denied_amount IS NULL OR denied_amount>=0),
 adjusted_amount numeric(14,2),
 adjustment_reason varchar(2000),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,advice_id,line_number),
 FOREIGN KEY(tenant_id,advice_id) REFERENCES insurance_remittance_advices(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,patient_id) REFERENCES patient_insurer_associations(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,patient_id) REFERENCES insurance_claims(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,submission_id) REFERENCES insurance_claim_submissions(tenant_id,claim_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,supporting_evidence_id) REFERENCES insurance_prior_authorization_evidence(tenant_id,id) ON DELETE RESTRICT,
 CHECK(submission_id IS NULL OR claim_id IS NOT NULL),
 CHECK(supporting_evidence_id IS NULL OR submission_id IS NOT NULL),
 CHECK(adjustment_reason IS NULL OR length(trim(adjustment_reason))>0)
);
CREATE UNIQUE INDEX insurance_remittance_line_external_ref_unique
 ON insurance_remittance_lines(tenant_id,advice_id,external_line_reference)
 WHERE external_line_reference IS NOT NULL;
CREATE INDEX insurance_remittance_lines_patient_history
 ON insurance_remittance_lines(tenant_id,patient_id,advice_id,line_number);

CREATE OR REPLACE FUNCTION insurance_remittance_advice_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent insurance_remittance_advices;
BEGIN
 IF TG_OP='INSERT' THEN
  IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.recorded_by_user_id)
   OR (NEW.corrects_advice_id IS NOT NULL AND NOT EXISTS(
    SELECT 1 FROM insurance_remittance_advices p WHERE p.tenant_id=NEW.tenant_id AND p.id=NEW.corrects_advice_id AND p.insurer_id=NEW.insurer_id
   ))
  THEN RAISE EXCEPTION 'Invalid remittance advice ownership, actor, or correction lineage' USING ERRCODE='23514'; END IF;
 ELSE
  RAISE EXCEPTION 'Remittance advice records are immutable; record a correction' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_remittance_advice_guard_trigger
 BEFORE INSERT OR UPDATE ON insurance_remittance_advices FOR EACH ROW EXECUTE FUNCTION insurance_remittance_advice_guard();
CREATE OR REPLACE FUNCTION insurance_remittance_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE advice insurance_remittance_advices; association patient_insurer_associations;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Remittance lines are immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO advice FROM insurance_remittance_advices WHERE tenant_id=NEW.tenant_id AND id=NEW.advice_id;
 SELECT * INTO association FROM patient_insurer_associations WHERE tenant_id=NEW.tenant_id AND id=NEW.association_id AND patient_id=NEW.patient_id;
 IF advice.id IS NULL OR association.id IS NULL OR association.insurer_id IS DISTINCT FROM advice.insurer_id
  OR (NEW.claim_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.claim_id AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id))
  OR (NEW.submission_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claim_submissions s WHERE s.tenant_id=NEW.tenant_id AND s.claim_id=NEW.claim_id AND s.id=NEW.submission_id))
  OR (NEW.supporting_evidence_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_evidence e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.supporting_evidence_id AND e.patient_id=NEW.patient_id AND e.target_type='submission' AND e.submission_id=NEW.submission_id))
 THEN RAISE EXCEPTION 'Invalid remittance patient, claim, submission, insurer, or evidence reference' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_remittance_line_guard_trigger
 BEFORE INSERT OR UPDATE ON insurance_remittance_lines FOR EACH ROW EXECUTE FUNCTION insurance_remittance_line_guard();
CREATE OR REPLACE FUNCTION insurance_remittance_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Remittance advice history is immutable; record a correction' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurance_remittance_advice_immutable BEFORE UPDATE OR DELETE ON insurance_remittance_advices FOR EACH ROW EXECUTE FUNCTION insurance_remittance_immutable();
CREATE TRIGGER insurance_remittance_advice_no_truncate BEFORE TRUNCATE ON insurance_remittance_advices FOR EACH STATEMENT EXECUTE FUNCTION insurance_remittance_immutable();
CREATE TRIGGER insurance_remittance_line_immutable BEFORE UPDATE OR DELETE ON insurance_remittance_lines FOR EACH ROW EXECUTE FUNCTION insurance_remittance_immutable();
CREATE TRIGGER insurance_remittance_line_no_truncate BEFORE TRUNCATE ON insurance_remittance_lines FOR EACH STATEMENT EXECUTE FUNCTION insurance_remittance_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurance_remittance_advices')->exists() || DB::table('insurance_remittance_lines')->exists()) {
            throw new RuntimeException('Preserve remittance advice and correction history; populated rollback is prohibited.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER insurance_remittance_advice_guard_trigger ON insurance_remittance_advices;
DROP TRIGGER insurance_remittance_line_guard_trigger ON insurance_remittance_lines;
DROP TRIGGER insurance_remittance_advice_immutable ON insurance_remittance_advices;
DROP TRIGGER insurance_remittance_advice_no_truncate ON insurance_remittance_advices;
DROP TRIGGER insurance_remittance_line_immutable ON insurance_remittance_lines;
DROP TRIGGER insurance_remittance_line_no_truncate ON insurance_remittance_lines;
DROP FUNCTION insurance_remittance_advice_guard();
DROP FUNCTION insurance_remittance_line_guard();
DROP FUNCTION insurance_remittance_immutable();
DROP TABLE insurance_remittance_lines;
DROP TABLE insurance_remittance_advices;
SQL);
    }
};
