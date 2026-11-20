<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Prior authorizations require PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE patient_insurer_associations ADD CONSTRAINT prior_auth_association_patient_unique UNIQUE(tenant_id,id,patient_id);
ALTER TABLE insurance_estimates ADD CONSTRAINT prior_auth_estimate_patient_unique UNIQUE(tenant_id,id,patient_id);
ALTER TABLE insurance_claims ADD CONSTRAINT prior_auth_claim_patient_unique UNIQUE(tenant_id,id,patient_id);

CREATE TABLE insurance_prior_authorizations (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint NOT NULL, association_id bigint NOT NULL, policy_revision_id bigint, claim_id bigint,
 treatment_plan_item_id bigint, estimate_id bigint, request_identifier uuid NOT NULL,
 request_date date NOT NULL, command_key uuid NOT NULL, request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 treatment_description text NOT NULL CHECK(length(trim(treatment_description)) BETWEEN 1 AND 4000),
 procedure_details jsonb NOT NULL DEFAULT '{}'::jsonb CHECK(jsonb_typeof(procedure_details)='object'),
 requested_amount numeric(14,2) NOT NULL CHECK(requested_amount>=0), currency char(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 status varchar(24) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','requested','pending','approved','partially_approved','denied','cancelled','expired')),
 lock_version integer NOT NULL DEFAULT 1 CHECK(lock_version>0), created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 updated_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()), updated_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,request_identifier), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,patient_id) REFERENCES patient_insurer_associations(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,association_id,policy_revision_id) REFERENCES patient_insurance_policy_revisions(tenant_id,association_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,patient_id) REFERENCES insurance_claims(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,estimate_id,patient_id) REFERENCES insurance_estimates(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,treatment_plan_item_id) REFERENCES treatment_plan_items(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX prior_auth_patient_history ON insurance_prior_authorizations(tenant_id,patient_id,id DESC);
CREATE INDEX prior_auth_claim_idx ON insurance_prior_authorizations(tenant_id,claim_id);

CREATE TABLE insurance_prior_authorization_events (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, authorization_id bigint NOT NULL, version integer NOT NULL CHECK(version>0),
 from_status varchar(24) CHECK(from_status IS NULL OR from_status IN ('draft','requested','pending','approved','partially_approved','denied','cancelled','expired')),
 to_status varchar(24) NOT NULL CHECK(to_status IN ('draft','requested','pending','approved','partially_approved','denied','cancelled','expired')),
 event_type varchar(40) NOT NULL CHECK(event_type IN ('created','draft_updated','request_recorded','insurer_acknowledged','decision_recorded','additional_information_requested','cancelled','expired','claim_linked','correction_recorded')),
 reason text, communication_channel varchar(24), actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, occurred_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,authorization_id,version),
 FOREIGN KEY(tenant_id,authorization_id) REFERENCES insurance_prior_authorizations(tenant_id,id) ON DELETE RESTRICT
);

CREATE TABLE insurance_prior_authorization_decisions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, authorization_id bigint NOT NULL, event_id bigint NOT NULL,
 corrects_decision_id bigint,
 decision_type varchar(40) NOT NULL CHECK(decision_type IN ('approved','partially_approved','denied','additional_information_requested','acknowledged')),
 insurer_reference_ciphertext text, authorized_amount numeric(14,2) CHECK(authorized_amount IS NULL OR authorized_amount>=0),
 currency char(3) CHECK(currency IS NULL OR currency ~ '^[A-Z]{3}$'), authorized_scope text,
 valid_from date, valid_through date, communication_channel varchar(24) NOT NULL CHECK(communication_channel IN ('phone','portal','email','fax','paper','other')),
 decision_at timestamp NOT NULL, recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 source_provenance text NOT NULL CHECK(length(trim(source_provenance)) BETWEEN 1 AND 2000),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,event_id), UNIQUE(tenant_id,corrects_decision_id),
 FOREIGN KEY(tenant_id,authorization_id) REFERENCES insurance_prior_authorizations(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,event_id) REFERENCES insurance_prior_authorization_events(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,corrects_decision_id) REFERENCES insurance_prior_authorization_decisions(tenant_id,id) ON DELETE RESTRICT,
 CHECK((authorized_amount IS NULL AND currency IS NULL) OR (authorized_amount IS NOT NULL AND currency IS NOT NULL)),
 CHECK(valid_from IS NULL OR valid_through IS NULL OR valid_through>=valid_from),
 CHECK(corrects_decision_id IS NULL OR corrects_decision_id<>id)
);

CREATE TABLE insurance_prior_authorization_evidence (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, patient_id bigint NOT NULL, version_id bigint NOT NULL,
 target_type varchar(24) NOT NULL CHECK(target_type IN ('authorization','decision','claim','submission')),
 authorization_id bigint, decision_id bigint, claim_id bigint, submission_id bigint,
 command_key uuid NOT NULL, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,version_id) REFERENCES document_versions(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,authorization_id) REFERENCES insurance_prior_authorizations(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,decision_id) REFERENCES insurance_prior_authorization_decisions(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id) REFERENCES insurance_claims(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,submission_id) REFERENCES insurance_claim_submissions(tenant_id,id) ON DELETE RESTRICT,
 CHECK((target_type='authorization' AND authorization_id IS NOT NULL AND decision_id IS NULL AND claim_id IS NULL AND submission_id IS NULL) OR
       (target_type='decision' AND authorization_id IS NULL AND decision_id IS NOT NULL AND claim_id IS NULL AND submission_id IS NULL) OR
       (target_type='claim' AND authorization_id IS NULL AND decision_id IS NULL AND claim_id IS NOT NULL AND submission_id IS NULL) OR
       (target_type='submission' AND authorization_id IS NULL AND decision_id IS NULL AND claim_id IS NULL AND submission_id IS NOT NULL))
);
CREATE INDEX prior_auth_evidence_target ON insurance_prior_authorization_evidence(tenant_id,target_type,authorization_id,decision_id,claim_id,submission_id);
CREATE UNIQUE INDEX prior_auth_evidence_auth_unique ON insurance_prior_authorization_evidence(tenant_id,version_id,authorization_id) WHERE target_type='authorization';
CREATE UNIQUE INDEX prior_auth_evidence_decision_unique ON insurance_prior_authorization_evidence(tenant_id,version_id,decision_id) WHERE target_type='decision';
CREATE UNIQUE INDEX prior_auth_evidence_claim_unique ON insurance_prior_authorization_evidence(tenant_id,version_id,claim_id) WHERE target_type='claim';
CREATE UNIQUE INDEX prior_auth_evidence_submission_unique ON insurance_prior_authorization_evidence(tenant_id,version_id,submission_id) WHERE target_type='submission';

CREATE OR REPLACE FUNCTION evidence_retained(v bigint) RETURNS boolean LANGUAGE sql VOLATILE AS $$
 SELECT s.classification<>'clinical' OR EXISTS(SELECT 1 FROM document_protections p WHERE p.version_id=dv.id AND p.quarantined)
 OR EXISTS(SELECT 1 FROM document_evidence_links l JOIN document_versions linked ON linked.id=l.version_id WHERE linked.series_id=s.id)
 OR EXISTS(SELECT 1 FROM insurance_prior_authorization_evidence e JOIN document_versions linked ON linked.id=e.version_id WHERE linked.series_id=s.id)
 OR EXISTS(SELECT 1 FROM document_hold_events h WHERE h.series_id=s.id AND h.action='placed' AND NOT EXISTS(SELECT 1 FROM document_hold_events r WHERE r.release_of_id=h.id))
 FROM document_versions dv JOIN document_series s ON s.id=dv.series_id WHERE dv.id=v
$$;

CREATE OR REPLACE FUNCTION prior_auth_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' OR NEW.lock_version<>1 OR NEW.created_by_user_id<>NEW.updated_by_user_id
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.treatment_plan_item_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM treatment_plan_items i JOIN treatment_plans p ON p.tenant_id=i.tenant_id AND p.id=i.treatment_plan_id WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.treatment_plan_item_id AND p.patient_id=NEW.patient_id))
  THEN RAISE EXCEPTION 'Invalid prior authorization creation lineage' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.association_id,NEW.request_identifier,NEW.created_by_user_id,NEW.created_at)
       IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.association_id,OLD.request_identifier,OLD.created_by_user_id,OLD.created_at)
   OR OLD.status IN ('cancelled','expired') OR (OLD.status IN ('approved','partially_approved','denied') AND NEW.status NOT IN ('expired','approved','partially_approved','denied')) OR NEW.lock_version<>OLD.lock_version+1
   OR (NEW.status='expired' AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions d WHERE d.tenant_id=NEW.tenant_id AND d.authorization_id=NEW.id AND d.decision_type IN ('approved','partially_approved') AND d.valid_through<CURRENT_DATE))
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.updated_by_user_id)
   OR NOT ((OLD.status='draft' AND NEW.status IN ('draft','requested','cancelled')) OR
           (OLD.status='requested' AND NEW.status IN ('pending','approved','partially_approved','denied','cancelled')) OR
           (OLD.status='pending' AND NEW.status IN ('pending','approved','partially_approved','denied','cancelled')) OR
           (OLD.status IN ('approved','partially_approved') AND NEW.status='expired') OR
           (OLD.status IN ('approved','partially_approved','denied') AND NEW.status IN ('approved','partially_approved','denied')))
   OR (NEW.claim_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.claim_id AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.treatment_plan_item_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM treatment_plan_items i JOIN treatment_plans p ON p.tenant_id=i.tenant_id AND p.id=i.treatment_plan_id WHERE i.tenant_id=NEW.tenant_id AND i.id=NEW.treatment_plan_item_id AND p.patient_id=NEW.patient_id))
  THEN RAISE EXCEPTION 'Invalid prior authorization update or transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER prior_auth_guard_trigger BEFORE INSERT OR UPDATE ON insurance_prior_authorizations FOR EACH ROW EXECUTE FUNCTION prior_auth_guard();
CREATE OR REPLACE FUNCTION prior_auth_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Prior authorization history is append-only' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER prior_auth_events_immutable BEFORE UPDATE OR DELETE ON insurance_prior_authorization_events FOR EACH ROW EXECUTE FUNCTION prior_auth_append_only();
CREATE TRIGGER prior_auth_events_no_truncate BEFORE TRUNCATE ON insurance_prior_authorization_events FOR EACH STATEMENT EXECUTE FUNCTION prior_auth_append_only();
CREATE TRIGGER prior_auth_decisions_immutable BEFORE UPDATE OR DELETE ON insurance_prior_authorization_decisions FOR EACH ROW EXECUTE FUNCTION prior_auth_append_only();
CREATE TRIGGER prior_auth_decisions_no_truncate BEFORE TRUNCATE ON insurance_prior_authorization_decisions FOR EACH STATEMENT EXECUTE FUNCTION prior_auth_append_only();
CREATE OR REPLACE FUNCTION prior_auth_decision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e insurance_prior_authorization_events; a insurance_prior_authorizations;
BEGIN
 SELECT * INTO e FROM insurance_prior_authorization_events WHERE tenant_id=NEW.tenant_id AND id=NEW.event_id;
 SELECT * INTO a FROM insurance_prior_authorizations WHERE tenant_id=NEW.tenant_id AND id=NEW.authorization_id;
 IF e.id IS NULL OR a.id IS NULL OR e.authorization_id<>a.id OR e.actor_id<>NEW.recorded_by_user_id
  OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.recorded_by_user_id)
  OR (NEW.decision_type='acknowledged' AND e.event_type<>'insurer_acknowledged')
  OR (NEW.decision_type='additional_information_requested' AND e.event_type<>'additional_information_requested')
  OR (NEW.decision_type IN ('approved','partially_approved','denied') AND e.event_type NOT IN ('decision_recorded','correction_recorded'))
  OR (e.event_type='decision_recorded' AND NEW.decision_type<>e.to_status)
  OR (e.event_type='correction_recorded' AND NEW.decision_type<>e.to_status)
  OR (e.event_type='correction_recorded' AND (NEW.corrects_decision_id IS NULL OR NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions prior JOIN insurance_prior_authorization_events pe ON pe.tenant_id=prior.tenant_id AND pe.id=prior.event_id WHERE prior.tenant_id=NEW.tenant_id AND prior.id=NEW.corrects_decision_id AND prior.authorization_id=NEW.authorization_id AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions newer JOIN insurance_prior_authorization_events ne ON ne.tenant_id=newer.tenant_id AND ne.id=newer.event_id WHERE newer.tenant_id=NEW.tenant_id AND newer.authorization_id=NEW.authorization_id AND ne.version>pe.version))))
  OR (e.event_type<>'correction_recorded' AND NEW.corrects_decision_id IS NOT NULL)
  OR (NEW.currency IS NOT NULL AND NEW.currency<>a.currency)
 THEN RAISE EXCEPTION 'Invalid manual insurer decision event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER prior_auth_decision_insert_guard BEFORE INSERT ON insurance_prior_authorization_decisions FOR EACH ROW EXECUTE FUNCTION prior_auth_decision_guard();
CREATE TRIGGER prior_auth_evidence_immutable BEFORE UPDATE OR DELETE ON insurance_prior_authorization_evidence FOR EACH ROW EXECUTE FUNCTION prior_auth_append_only();
CREATE TRIGGER prior_auth_evidence_no_truncate BEFORE TRUNCATE ON insurance_prior_authorization_evidence FOR EACH STATEMENT EXECUTE FUNCTION prior_auth_append_only();
CREATE OR REPLACE FUNCTION prior_auth_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a insurance_prior_authorizations;
BEGIN
 SELECT * INTO a FROM insurance_prior_authorizations WHERE tenant_id=NEW.tenant_id AND id=NEW.authorization_id;
 IF a.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
  OR NEW.version<>a.lock_version OR NEW.to_status<>a.status
  OR (NEW.event_type='created' AND (NEW.version<>1 OR NEW.from_status IS NOT NULL OR NEW.to_status<>'draft'))
  OR (NEW.event_type='draft_updated' AND NOT(NEW.from_status='draft' AND NEW.to_status='draft'))
  OR (NEW.event_type='request_recorded' AND NOT(NEW.from_status='draft' AND NEW.to_status='requested'))
  OR (NEW.event_type='insurer_acknowledged' AND NOT(NEW.from_status IN ('requested','pending') AND NEW.to_status='pending'))
  OR (NEW.event_type='additional_information_requested' AND NOT(NEW.from_status IN ('requested','pending') AND NEW.to_status='pending'))
  OR (NEW.event_type='decision_recorded' AND NOT(NEW.from_status IN ('requested','pending') AND NEW.to_status IN ('approved','partially_approved','denied')))
  OR (NEW.event_type='correction_recorded' AND NOT(NEW.from_status IN ('approved','partially_approved','denied') AND NEW.to_status IN ('approved','partially_approved','denied') AND NEW.reason IS NOT NULL AND length(trim(NEW.reason))>0))
  OR (NEW.event_type='expired' AND NOT(NEW.from_status IN ('approved','partially_approved') AND NEW.to_status='expired'))
  OR (NEW.event_type='claim_linked' AND NOT(NEW.to_status=NEW.from_status))
  OR (NEW.event_type='cancelled' AND NOT(NEW.from_status IN ('draft','requested','pending') AND NEW.to_status='cancelled'))
 THEN RAISE EXCEPTION 'Invalid prior authorization lifecycle event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER prior_auth_event_insert_guard BEFORE INSERT ON insurance_prior_authorization_events FOR EACH ROW EXECUTE FUNCTION prior_auth_event_guard();
CREATE OR REPLACE FUNCTION prior_auth_require_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_events e WHERE e.tenant_id=NEW.tenant_id AND e.authorization_id=NEW.id AND e.version=NEW.lock_version)
 THEN RAISE EXCEPTION 'Authorization lifecycle event required for each version' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM insurance_prior_authorization_events e WHERE e.tenant_id=NEW.tenant_id AND e.authorization_id=NEW.id AND e.version=NEW.lock_version AND e.event_type IN ('decision_recorded','insurer_acknowledged','additional_information_requested','correction_recorded'))
  AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions d JOIN insurance_prior_authorization_events e ON e.tenant_id=d.tenant_id AND e.id=d.event_id WHERE e.tenant_id=NEW.tenant_id AND e.authorization_id=NEW.id AND e.version=NEW.lock_version)
 THEN RAISE EXCEPTION 'Manual insurer decision record required for decision event' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM insurance_prior_authorization_events e WHERE e.tenant_id=NEW.tenant_id AND e.authorization_id=NEW.id AND e.version=NEW.lock_version AND e.from_status IN ('approved','partially_approved','denied') AND e.to_status IN ('approved','partially_approved','denied'))
  AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions d JOIN insurance_prior_authorization_events e ON e.tenant_id=d.tenant_id AND e.id=d.event_id WHERE e.tenant_id=NEW.tenant_id AND e.authorization_id=NEW.id AND e.version=NEW.lock_version AND e.event_type='correction_recorded' AND d.corrects_decision_id IS NOT NULL)
 THEN RAISE EXCEPTION 'A terminal decision can only change through an explicit correction' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER prior_auth_require_history_trigger AFTER INSERT OR UPDATE ON insurance_prior_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION prior_auth_require_history();
CREATE OR REPLACE FUNCTION prior_auth_evidence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE doc_series document_series; v document_versions;
BEGIN
 SELECT * INTO v FROM document_versions WHERE tenant_id=NEW.tenant_id AND id=NEW.version_id;
 SELECT * INTO doc_series FROM document_series WHERE tenant_id=NEW.tenant_id AND id=v.series_id;
 IF v.id IS NULL OR doc_series.id IS NULL OR v.scan_status<>'clean' OR doc_series.patient_id IS DISTINCT FROM NEW.patient_id
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
   OR NOT EXISTS(SELECT 1 FROM document_protections p WHERE p.tenant_id=NEW.tenant_id AND p.version_id=v.id AND NOT p.quarantined AND p.purge_state='retained')
   OR (NEW.target_type='authorization' AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorizations a WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.authorization_id AND a.patient_id=NEW.patient_id))
   OR (NEW.target_type='decision' AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_decisions d JOIN insurance_prior_authorizations a ON a.tenant_id=d.tenant_id AND a.id=d.authorization_id WHERE d.tenant_id=NEW.tenant_id AND d.id=NEW.decision_id AND a.patient_id=NEW.patient_id))
   OR (NEW.target_type='claim' AND NOT EXISTS(SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.claim_id AND c.patient_id=NEW.patient_id))
   OR (NEW.target_type='submission' AND NOT EXISTS(SELECT 1 FROM insurance_claim_submissions s JOIN insurance_claims c ON c.tenant_id=s.tenant_id AND c.id=s.claim_id WHERE s.tenant_id=NEW.tenant_id AND s.id=NEW.submission_id AND c.patient_id=NEW.patient_id))
 THEN RAISE EXCEPTION 'Invalid insurance evidence target or protected version' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER prior_auth_evidence_guard_trigger BEFORE INSERT ON insurance_prior_authorization_evidence FOR EACH ROW EXECUTE FUNCTION prior_auth_evidence_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['insurance_prior_authorization_evidence', 'insurance_prior_authorization_decisions', 'insurance_prior_authorization_events', 'insurance_prior_authorizations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Export prior authorization history and evidence before rollback.');
            }
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS prior_auth_evidence_no_truncate ON insurance_prior_authorization_evidence;
DROP TRIGGER IF EXISTS prior_auth_evidence_immutable ON insurance_prior_authorization_evidence;
DROP TRIGGER IF EXISTS prior_auth_decisions_no_truncate ON insurance_prior_authorization_decisions;
DROP TRIGGER IF EXISTS prior_auth_decisions_immutable ON insurance_prior_authorization_decisions;
DROP TRIGGER IF EXISTS prior_auth_decision_insert_guard ON insurance_prior_authorization_decisions;
DROP TRIGGER IF EXISTS prior_auth_events_no_truncate ON insurance_prior_authorization_events;
DROP TRIGGER IF EXISTS prior_auth_events_immutable ON insurance_prior_authorization_events;
DROP TRIGGER IF EXISTS prior_auth_guard_trigger ON insurance_prior_authorizations;
DROP TRIGGER IF EXISTS prior_auth_require_history_trigger ON insurance_prior_authorizations;
DROP TRIGGER IF EXISTS prior_auth_event_insert_guard ON insurance_prior_authorization_events;
DROP TRIGGER IF EXISTS prior_auth_evidence_guard_trigger ON insurance_prior_authorization_evidence;
DROP FUNCTION IF EXISTS prior_auth_evidence_guard();
DROP FUNCTION IF EXISTS prior_auth_require_history(); DROP FUNCTION IF EXISTS prior_auth_event_guard(); DROP FUNCTION IF EXISTS prior_auth_decision_guard();
DROP FUNCTION IF EXISTS prior_auth_append_only(); DROP FUNCTION IF EXISTS prior_auth_guard();
DROP TABLE insurance_prior_authorization_evidence; DROP TABLE insurance_prior_authorization_decisions;
DROP TABLE insurance_prior_authorization_events; DROP TABLE insurance_prior_authorizations;
ALTER TABLE insurance_claims DROP CONSTRAINT prior_auth_claim_patient_unique;
ALTER TABLE insurance_estimates DROP CONSTRAINT prior_auth_estimate_patient_unique;
ALTER TABLE patient_insurer_associations DROP CONSTRAINT prior_auth_association_patient_unique;
CREATE OR REPLACE FUNCTION evidence_retained(v bigint) RETURNS boolean LANGUAGE sql VOLATILE AS $$
 SELECT s.classification<>'clinical' OR EXISTS(SELECT 1 FROM document_protections p WHERE p.version_id=dv.id AND p.quarantined) OR EXISTS(SELECT 1 FROM document_evidence_links l JOIN document_versions linked ON linked.id=l.version_id WHERE linked.series_id=s.id)
 OR EXISTS(SELECT 1 FROM document_hold_events h WHERE h.series_id=s.id AND h.action='placed' AND NOT EXISTS(SELECT 1 FROM document_hold_events r WHERE r.release_of_id=h.id))
 FROM document_versions dv JOIN document_series s ON s.id=dv.series_id WHERE dv.id=v
$$;
SQL);
    }
};
