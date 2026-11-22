<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurer obligation register requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE invoices ADD CONSTRAINT insurer_obligations_invoice_tenant_unique UNIQUE(tenant_id,id);
ALTER TABLE invoices ADD CONSTRAINT insurer_obligations_invoice_patient_unique UNIQUE(tenant_id,id,patient_id);
ALTER TABLE invoice_items ADD CONSTRAINT insurer_obligations_item_invoice_unique UNIQUE(tenant_id,invoice_id,id);
ALTER TABLE insurance_claims ADD CONSTRAINT insurer_obligations_claim_source_unique UNIQUE(tenant_id,id,patient_id,association_id);
ALTER TABLE patient_insurer_associations ADD CONSTRAINT insurer_obligations_association_source_unique UNIQUE(tenant_id,id,patient_id,insurer_id);

CREATE TABLE insurer_obligations (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint NOT NULL,
 insurer_id bigint NOT NULL,
 association_id bigint NOT NULL,
 invoice_id bigint NOT NULL,
 invoice_item_id bigint NOT NULL,
 claim_id bigint,
 submission_id bigint,
 claim_response_id bigint,
 supporting_evidence_id bigint,
 proposed_amount numeric(10,2) NOT NULL CHECK(proposed_amount>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 source_provenance varchar(2000) NOT NULL CHECK(length(trim(source_provenance))>0),
 proposed_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 proposed_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 command_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,patient_id,insurer_id) REFERENCES patient_insurer_associations(tenant_id,id,patient_id,insurer_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,invoice_id,patient_id) REFERENCES invoices(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,invoice_id,invoice_item_id) REFERENCES invoice_items(tenant_id,invoice_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,patient_id,association_id) REFERENCES insurance_claims(tenant_id,id,patient_id,association_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,submission_id) REFERENCES insurance_claim_submissions(tenant_id,claim_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,claim_response_id) REFERENCES insurance_claim_responses(tenant_id,claim_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,supporting_evidence_id) REFERENCES insurance_prior_authorization_evidence(tenant_id,id) ON DELETE RESTRICT,
 CHECK((claim_id IS NULL AND submission_id IS NULL AND claim_response_id IS NULL) OR (claim_id IS NOT NULL AND submission_id IS NOT NULL)),
 CHECK(claim_response_id IS NULL OR (claim_id IS NOT NULL AND submission_id IS NOT NULL))
);
CREATE INDEX insurer_obligations_invoice_history ON insurer_obligations(tenant_id,invoice_id,invoice_item_id,id DESC);
CREATE INDEX insurer_obligations_patient_history ON insurer_obligations(tenant_id,patient_id,id DESC);
CREATE INDEX insurer_obligations_insurer_history ON insurer_obligations(tenant_id,insurer_id,id DESC);

CREATE TABLE insurer_obligation_approvals (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 obligation_id bigint NOT NULL,
 invoice_item_id bigint NOT NULL,
 approved_amount numeric(10,2) NOT NULL CHECK(approved_amount>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 approval_reason varchar(2000) NOT NULL CHECK(length(trim(approval_reason))>0),
 approved_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 approved_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 command_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,obligation_id), UNIQUE(tenant_id,invoice_item_id), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,obligation_id) REFERENCES insurer_obligations(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX insurer_obligation_approvals_history ON insurer_obligation_approvals(tenant_id,invoice_item_id,approved_at DESC);

CREATE OR REPLACE FUNCTION insurer_obligation_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invoice_row invoices; line_row invoice_items;
BEGIN
 SELECT * INTO invoice_row FROM invoices WHERE tenant_id=NEW.tenant_id AND id=NEW.invoice_id AND patient_id=NEW.patient_id;
 SELECT * INTO line_row FROM invoice_items WHERE tenant_id=NEW.tenant_id AND invoice_id=NEW.invoice_id AND id=NEW.invoice_item_id;
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.proposed_by_user_id)
  OR invoice_row.id IS NULL OR invoice_row.status NOT IN ('issued','partially_paid') OR invoice_row.currency IS DISTINCT FROM NEW.currency
  OR line_row.id IS NULL OR NEW.proposed_amount>line_row.line_total
  OR NOT EXISTS(SELECT 1 FROM patient_insurer_associations a WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.association_id AND a.patient_id=NEW.patient_id AND a.insurer_id=NEW.insurer_id)
  OR (NEW.claim_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.claim_id AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id))
  OR (NEW.submission_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claim_submissions s WHERE s.tenant_id=NEW.tenant_id AND s.claim_id=NEW.claim_id AND s.id=NEW.submission_id))
  OR (NEW.claim_response_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claim_responses r WHERE r.tenant_id=NEW.tenant_id AND r.claim_id=NEW.claim_id AND r.submission_id=NEW.submission_id AND r.id=NEW.claim_response_id AND r.response_type IN ('approved','partially_approved')))
  OR (NEW.supporting_evidence_id IS NOT NULL AND NOT EXISTS(
      SELECT 1 FROM insurance_prior_authorization_evidence e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.supporting_evidence_id AND e.patient_id=NEW.patient_id
       AND ((e.target_type='claim' AND e.claim_id=NEW.claim_id) OR (e.target_type='submission' AND e.submission_id=NEW.submission_id))))
 THEN RAISE EXCEPTION 'Invalid insurer obligation ownership, invoice, association, claim, submission, evidence, currency, or amount' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION insurer_obligation_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE obligation insurer_obligations; invoice_row invoices; line_row invoice_items;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Insurer obligation approvals are immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO obligation FROM insurer_obligations WHERE tenant_id=NEW.tenant_id AND id=NEW.obligation_id;
 IF obligation.id IS NULL THEN RAISE EXCEPTION 'Insurer obligation not found' USING ERRCODE='23503'; END IF;
 SELECT * INTO invoice_row FROM invoices WHERE tenant_id=obligation.tenant_id AND id=obligation.invoice_id AND patient_id=obligation.patient_id FOR UPDATE;
 SELECT * INTO line_row FROM invoice_items WHERE tenant_id=obligation.tenant_id AND invoice_id=obligation.invoice_id AND id=obligation.invoice_item_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.approved_by_user_id)
  OR invoice_row.id IS NULL OR invoice_row.status NOT IN ('issued','partially_paid') OR line_row.id IS NULL
  OR NEW.invoice_item_id<>obligation.invoice_item_id OR NEW.approved_amount<>obligation.proposed_amount OR NEW.currency<>obligation.currency
  OR NEW.approved_amount>line_row.line_total
 THEN RAISE EXCEPTION 'Approval must exactly approve the proposed obligation for its issued invoice line' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION insurer_obligation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Insurer obligation and approval history is immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurer_obligation_insert_guard BEFORE INSERT ON insurer_obligations FOR EACH ROW EXECUTE FUNCTION insurer_obligation_insert_guard();
CREATE TRIGGER insurer_obligation_immutable BEFORE UPDATE OR DELETE ON insurer_obligations FOR EACH ROW EXECUTE FUNCTION insurer_obligation_immutable();
CREATE TRIGGER insurer_obligation_no_truncate BEFORE TRUNCATE ON insurer_obligations FOR EACH STATEMENT EXECUTE FUNCTION insurer_obligation_immutable();
CREATE TRIGGER insurer_obligation_approval_guard BEFORE INSERT ON insurer_obligation_approvals FOR EACH ROW EXECUTE FUNCTION insurer_obligation_approval_guard();
CREATE TRIGGER insurer_obligation_approval_immutable BEFORE UPDATE OR DELETE ON insurer_obligation_approvals FOR EACH ROW EXECUTE FUNCTION insurer_obligation_immutable();
CREATE TRIGGER insurer_obligation_approval_no_truncate BEFORE TRUNCATE ON insurer_obligation_approvals FOR EACH STATEMENT EXECUTE FUNCTION insurer_obligation_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurer_obligations')->exists() || DB::table('insurer_obligation_approvals')->exists()) {
            throw new RuntimeException('Preserve insurer obligation approval history; populated rollback is prohibited.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER insurer_obligation_insert_guard ON insurer_obligations;
DROP TRIGGER insurer_obligation_immutable ON insurer_obligations;
DROP TRIGGER insurer_obligation_no_truncate ON insurer_obligations;
DROP TRIGGER insurer_obligation_approval_guard ON insurer_obligation_approvals;
DROP TRIGGER insurer_obligation_approval_immutable ON insurer_obligation_approvals;
DROP TRIGGER insurer_obligation_approval_no_truncate ON insurer_obligation_approvals;
DROP FUNCTION insurer_obligation_insert_guard();
DROP FUNCTION insurer_obligation_approval_guard();
DROP FUNCTION insurer_obligation_immutable();
DROP TABLE insurer_obligation_approvals;
DROP TABLE insurer_obligations;
ALTER TABLE insurance_claims DROP CONSTRAINT insurer_obligations_claim_source_unique;
ALTER TABLE patient_insurer_associations DROP CONSTRAINT insurer_obligations_association_source_unique;
ALTER TABLE invoice_items DROP CONSTRAINT insurer_obligations_item_invoice_unique;
ALTER TABLE invoices DROP CONSTRAINT insurer_obligations_invoice_patient_unique;
ALTER TABLE invoices DROP CONSTRAINT insurer_obligations_invoice_tenant_unique;
SQL);
    }
};
