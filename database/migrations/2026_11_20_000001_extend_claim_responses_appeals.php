<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Claim response history requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claims_status_check;
ALTER TABLE insurance_claims ALTER COLUMN status TYPE varchar(40);
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_status_check CHECK(status IN ('draft','ready','submitted','acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied','cancelled'));
ALTER TABLE insurance_claim_events ALTER COLUMN from_status TYPE varchar(40);
ALTER TABLE insurance_claim_events ALTER COLUMN to_status TYPE varchar(40);
ALTER TABLE insurance_claim_events ALTER COLUMN event_type TYPE varchar(40);
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_from_status_check;
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_to_status_check;
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_from_status_check CHECK(from_status IS NULL OR from_status IN ('draft','ready','submitted','acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied','cancelled'));
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_to_status_check CHECK(to_status IN ('draft','ready','submitted','acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied','cancelled'));
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_event_type_check;
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_event_type_check CHECK(event_type IN ('created','validated','draft_updated','cancelled','submitted','insurer_response_recorded'));
ALTER TABLE insurance_claims ADD COLUMN correction_reason varchar(2000);
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claim_target_unique UNIQUE(tenant_id,id,patient_id);
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claim_correction_reason_check CHECK((corrects_claim_id IS NULL AND correction_reason IS NULL) OR (corrects_claim_id IS NOT NULL AND length(trim(correction_reason))>0));
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claim_not_self_correcting CHECK(corrects_claim_id IS NULL OR corrects_claim_id<>id);
CREATE UNIQUE INDEX insurance_claim_one_correction_per_predecessor ON insurance_claims(tenant_id,corrects_claim_id) WHERE corrects_claim_id IS NOT NULL;
ALTER TABLE insurance_claim_submissions ADD CONSTRAINT insurance_claim_submission_target_unique UNIQUE(tenant_id,claim_id,id);

CREATE OR REPLACE FUNCTION insurance_claim_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' OR NEW.lock_version<>1 OR NEW.created_by_user_id<>NEW.updated_by_user_id
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR NEW.corrects_claim_id IS NOT NULL AND NOT EXISTS(
    SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.corrects_claim_id AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id AND c.status IN ('submitted','rejected','denied')
  ) THEN RAISE EXCEPTION 'Invalid insurance claim creation or correction lineage' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.association_id,NEW.created_by_user_id,NEW.created_at,NEW.claim_identifier,NEW.corrects_claim_id,NEW.correction_reason)
     IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.association_id,OLD.created_by_user_id,OLD.created_at,OLD.claim_identifier,OLD.corrects_claim_id,OLD.correction_reason)
   OR OLD.status IN ('cancelled','rejected','approved','partially_approved','denied') OR NEW.lock_version<>OLD.lock_version+1
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.updated_by_user_id)
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.status='ready' AND (NOT EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)
       OR EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id AND l.gross_amount<=0)
       OR (SELECT count(DISTINCT currency) FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)>1))
   OR NOT ((OLD.status='draft' AND NEW.status IN ('draft','ready','cancelled'))
        OR (OLD.status='ready' AND NEW.status IN ('draft','ready','submitted','cancelled'))
        OR (OLD.status='submitted' AND NEW.status IN ('acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied'))
        OR (OLD.status='acknowledged' AND NEW.status IN ('under_review','additional_information_requested','rejected','approved','partially_approved','denied'))
        OR (OLD.status IN ('under_review','additional_information_requested') AND NEW.status IN ('acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied')))
  THEN RAISE EXCEPTION 'Invalid insurance claim update or lifecycle transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION insurance_claim_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c insurance_claims;
BEGIN
 SELECT * INTO c FROM insurance_claims WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id;
 IF c.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id) OR NEW.version<>c.lock_version OR NEW.to_status<>c.status
  OR (NEW.event_type='created' AND NOT (NEW.version=1 AND NEW.from_status IS NULL AND NEW.to_status='draft'))
  OR (NEW.event_type='validated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='ready'))
  OR (NEW.event_type='draft_updated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='draft'))
  OR (NEW.event_type='cancelled' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='cancelled'))
  OR (NEW.event_type='submitted' AND NOT (NEW.from_status='ready' AND NEW.to_status='submitted'))
  OR (NEW.event_type='insurer_response_recorded' AND (NOT (NEW.from_status IN ('submitted','acknowledged','under_review','additional_information_requested') AND NEW.to_status IN ('acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied')) OR NOT EXISTS(SELECT 1 FROM insurance_claim_responses r WHERE r.tenant_id=NEW.tenant_id AND r.claim_id=NEW.claim_id AND r.claim_version=NEW.version AND r.response_type=NEW.to_status AND r.recorded_by_user_id=NEW.actor_id)))
 THEN RAISE EXCEPTION 'Invalid insurance claim lifecycle event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;

CREATE TABLE insurance_claim_responses (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, patient_id bigint NOT NULL, claim_id bigint NOT NULL, submission_id bigint NOT NULL,
 response_type varchar(40) NOT NULL CHECK(response_type IN ('acknowledged','under_review','additional_information_requested','rejected','approved','partially_approved','denied')), claim_version integer NOT NULL CHECK(claim_version>1),
 insurer_response_at timestamp NOT NULL, recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()), recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 communication_channel varchar(16) NOT NULL CHECK(communication_channel IN ('phone','portal','email','fax','paper','other')),
 insurer_reference varchar(200), source_provenance varchar(2000) NOT NULL CHECK(length(trim(source_provenance))>0), notes text,
 requested_information text, response_deadline date, evidence_id bigint,
 approved_amount numeric(14,2) CHECK(approved_amount IS NULL OR approved_amount>=0), currency varchar(3) CHECK(currency IS NULL OR currency ~ '^[A-Z]{3}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,claim_id,id), UNIQUE(tenant_id,claim_id,claim_version), FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,patient_id) REFERENCES insurance_claims(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,submission_id) REFERENCES insurance_claim_submissions(tenant_id,claim_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,evidence_id) REFERENCES insurance_prior_authorization_evidence(tenant_id,id) ON DELETE RESTRICT,
 CHECK((approved_amount IS NULL AND currency IS NULL) OR (approved_amount IS NOT NULL AND currency IS NOT NULL)),
 CHECK(approved_amount IS NULL OR response_type IN ('approved','partially_approved')),
 CHECK((response_type='additional_information_requested' AND requested_information IS NOT NULL AND length(trim(requested_information))>0) OR (response_type<>'additional_information_requested' AND requested_information IS NULL AND response_deadline IS NULL))
);
CREATE INDEX insurance_claim_responses_history ON insurance_claim_responses(tenant_id,claim_id,id DESC);

CREATE TABLE insurance_claim_appeals (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, patient_id bigint NOT NULL, claim_id bigint NOT NULL, submission_id bigint NOT NULL, decision_response_id bigint NOT NULL,
 appeal_reason text NOT NULL CHECK(length(trim(appeal_reason))>0), status varchar(20) NOT NULL CHECK(status IN ('draft','submitted','acknowledged','under_review','approved','partially_approved','denied','withdrawn')),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, created_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,patient_id) REFERENCES insurance_claims(tenant_id,id,patient_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,submission_id) REFERENCES insurance_claim_submissions(tenant_id,claim_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,claim_id,decision_response_id) REFERENCES insurance_claim_responses(tenant_id,claim_id,id) ON DELETE RESTRICT
);
CREATE INDEX insurance_claim_appeals_history ON insurance_claim_appeals(tenant_id,claim_id,id DESC);
CREATE TABLE insurance_claim_appeal_events (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, appeal_id bigint NOT NULL, from_status varchar(20), to_status varchar(20) NOT NULL,
 event_type varchar(32) NOT NULL, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, occurred_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,appeal_id) REFERENCES insurance_claim_appeals(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE insurance_claim_appeal_submissions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, appeal_id bigint NOT NULL, sequence integer NOT NULL CHECK(sequence>0), channel varchar(16) NOT NULL,
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object'), submitted_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 submitted_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()), UNIQUE(tenant_id,id), UNIQUE(tenant_id,appeal_id,id), UNIQUE(tenant_id,appeal_id,sequence),
 FOREIGN KEY(tenant_id,appeal_id) REFERENCES insurance_claim_appeals(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE insurance_claim_appeal_responses (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, appeal_id bigint NOT NULL, appeal_submission_id bigint NOT NULL,
 response_type varchar(20) NOT NULL CHECK(response_type IN ('acknowledged','under_review','approved','partially_approved','denied')),
 insurer_response_at timestamp NOT NULL, recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()), recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 communication_channel varchar(16) NOT NULL CHECK(communication_channel IN ('phone','portal','email','fax','paper','other')),
 source_provenance varchar(2000) NOT NULL CHECK(length(trim(source_provenance))>0), insurer_reference varchar(200), notes text,
 approved_amount numeric(14,2) CHECK(approved_amount IS NULL OR approved_amount>=0), currency varchar(3) CHECK(currency IS NULL OR currency ~ '^[A-Z]{3}$'),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,appeal_id) REFERENCES insurance_claim_appeals(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,appeal_id,appeal_submission_id) REFERENCES insurance_claim_appeal_submissions(tenant_id,appeal_id,id) ON DELETE RESTRICT,
 CHECK((approved_amount IS NULL AND currency IS NULL) OR (approved_amount IS NOT NULL AND currency IS NOT NULL)),
 CHECK(approved_amount IS NULL OR response_type IN ('approved','partially_approved'))
);
CREATE OR REPLACE FUNCTION insurance_claim_appeal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' OR NOT EXISTS(SELECT 1 FROM insurance_claim_responses r WHERE r.tenant_id=NEW.tenant_id AND r.claim_id=NEW.claim_id AND r.patient_id=NEW.patient_id AND r.id=NEW.decision_response_id AND r.submission_id=NEW.submission_id AND r.response_type='denied')
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
  THEN RAISE EXCEPTION 'Invalid claim appeal or ineligible decision' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.claim_id,NEW.submission_id,NEW.decision_response_id,NEW.appeal_reason,NEW.created_by_user_id,NEW.created_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.claim_id,OLD.submission_id,OLD.decision_response_id,OLD.appeal_reason,OLD.created_by_user_id,OLD.created_at)
   OR NOT ((OLD.status='draft' AND NEW.status IN ('submitted','withdrawn'))
    OR (OLD.status='submitted' AND NEW.status IN ('acknowledged','under_review','withdrawn'))
    OR (OLD.status='acknowledged' AND NEW.status IN ('under_review','approved','partially_approved','denied'))
    OR (OLD.status='under_review' AND NEW.status IN ('acknowledged','under_review','approved','partially_approved','denied')))
  THEN RAISE EXCEPTION 'Invalid appeal update or lifecycle transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_appeal_guard_trigger BEFORE INSERT OR UPDATE ON insurance_claim_appeals FOR EACH ROW EXECUTE FUNCTION insurance_claim_appeal_guard();
CREATE OR REPLACE FUNCTION insurance_claim_response_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.recorded_by_user_id)
  OR (NEW.evidence_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_prior_authorization_evidence e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.evidence_id AND e.patient_id=NEW.patient_id AND e.target_type='submission' AND e.submission_id=NEW.submission_id))
 THEN RAISE EXCEPTION 'Invalid claim response actor or protected evidence association' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurance_claim_response_guard_trigger BEFORE INSERT ON insurance_claim_responses FOR EACH ROW EXECUTE FUNCTION insurance_claim_response_guard();
CREATE OR REPLACE FUNCTION insurance_claim_response_require_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM insurance_claims c JOIN insurance_claim_events e ON e.tenant_id=c.tenant_id AND e.claim_id=c.id AND e.version=NEW.claim_version
   WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.claim_id AND c.lock_version=NEW.claim_version AND e.event_type='insurer_response_recorded' AND e.to_status=NEW.response_type AND e.actor_id=NEW.recorded_by_user_id)
 THEN RAISE EXCEPTION 'Each claim response requires its lifecycle transition and event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER insurance_claim_response_require_event_trigger AFTER INSERT ON insurance_claim_responses DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION insurance_claim_response_require_event();
CREATE OR REPLACE FUNCTION claim_response_history_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Claim response and appeal history is immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER insurance_claim_responses_immutable BEFORE UPDATE OR DELETE ON insurance_claim_responses FOR EACH ROW EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_responses_no_truncate BEFORE TRUNCATE ON insurance_claim_responses FOR EACH STATEMENT EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_events_immutable BEFORE UPDATE OR DELETE ON insurance_claim_appeal_events FOR EACH ROW EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_submissions_immutable BEFORE UPDATE OR DELETE ON insurance_claim_appeal_submissions FOR EACH ROW EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_responses_immutable BEFORE UPDATE OR DELETE ON insurance_claim_appeal_responses FOR EACH ROW EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_events_no_truncate BEFORE TRUNCATE ON insurance_claim_appeal_events FOR EACH STATEMENT EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_submissions_no_truncate BEFORE TRUNCATE ON insurance_claim_appeal_submissions FOR EACH STATEMENT EXECUTE FUNCTION claim_response_history_immutable();
CREATE TRIGGER insurance_claim_appeal_responses_no_truncate BEFORE TRUNCATE ON insurance_claim_appeal_responses FOR EACH STATEMENT EXECUTE FUNCTION claim_response_history_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurance_claims')->whereIn('status', ['acknowledged', 'under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied'])->exists()
            || DB::table('insurance_claims')->whereNotNull('corrects_claim_id')->exists()
            || DB::table('insurance_claim_events')->where(function ($query) {
                $query->whereNotIn('event_type', ['created', 'validated', 'draft_updated', 'cancelled', 'submitted'])
                    ->orWhereNotIn('from_status', ['draft', 'ready', 'submitted', 'cancelled'])
                    ->orWhereNotIn('to_status', ['draft', 'ready', 'submitted', 'cancelled']);
            })->exists()) {
            throw new RuntimeException('Preserve claim decisions and correction lineage; populated rollback is prohibited.');
        }
        foreach (['insurance_claim_responses', 'insurance_claim_appeals', 'insurance_claim_appeal_events', 'insurance_claim_appeal_submissions', 'insurance_claim_appeal_responses'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve claim response and appeal history; populated rollback is prohibited.');
            }
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER insurance_claim_responses_immutable ON insurance_claim_responses;
DROP TRIGGER insurance_claim_responses_no_truncate ON insurance_claim_responses;
DROP TRIGGER insurance_claim_appeal_events_immutable ON insurance_claim_appeal_events;
DROP TRIGGER insurance_claim_appeal_submissions_immutable ON insurance_claim_appeal_submissions;
DROP TRIGGER insurance_claim_appeal_responses_immutable ON insurance_claim_appeal_responses;
DROP TRIGGER insurance_claim_appeal_events_no_truncate ON insurance_claim_appeal_events;
DROP TRIGGER insurance_claim_appeal_submissions_no_truncate ON insurance_claim_appeal_submissions;
DROP TRIGGER insurance_claim_appeal_responses_no_truncate ON insurance_claim_appeal_responses;
DROP TRIGGER insurance_claim_appeal_guard_trigger ON insurance_claim_appeals;
DROP TRIGGER insurance_claim_response_guard_trigger ON insurance_claim_responses;
DROP TRIGGER insurance_claim_response_require_event_trigger ON insurance_claim_responses;
DROP FUNCTION claim_response_history_immutable();
DROP FUNCTION insurance_claim_appeal_guard();
DROP FUNCTION insurance_claim_response_guard();
DROP FUNCTION insurance_claim_response_require_event();
DROP TABLE insurance_claim_appeal_responses; DROP TABLE insurance_claim_appeal_submissions; DROP TABLE insurance_claim_appeal_events; DROP TABLE insurance_claim_appeals; DROP TABLE insurance_claim_responses;
DROP INDEX insurance_claim_one_correction_per_predecessor;
ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claim_not_self_correcting;
ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claim_target_unique;
ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claim_correction_reason_check; ALTER TABLE insurance_claims DROP COLUMN correction_reason;
ALTER TABLE insurance_claim_submissions DROP CONSTRAINT insurance_claim_submission_target_unique;
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_event_type_check;
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_event_type_check CHECK(event_type IN ('created','validated','draft_updated','cancelled','submitted'));
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_from_status_check;
ALTER TABLE insurance_claim_events DROP CONSTRAINT insurance_claim_events_to_status_check;
ALTER TABLE insurance_claim_events ALTER COLUMN from_status TYPE varchar(16);
ALTER TABLE insurance_claim_events ALTER COLUMN to_status TYPE varchar(16);
ALTER TABLE insurance_claim_events ALTER COLUMN event_type TYPE varchar(24);
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_from_status_check CHECK(from_status IS NULL OR from_status IN ('draft','ready','submitted','cancelled'));
ALTER TABLE insurance_claim_events ADD CONSTRAINT insurance_claim_events_to_status_check CHECK(to_status IN ('draft','ready','submitted','cancelled'));
ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claims_status_check;
ALTER TABLE insurance_claims ALTER COLUMN status TYPE varchar(16);
ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_status_check CHECK(status IN ('draft','ready','submitted','cancelled'));

CREATE OR REPLACE FUNCTION insurance_claim_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status<>'draft' OR NEW.lock_version<>1 OR NEW.created_by_user_id<>NEW.updated_by_user_id
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.created_by_user_id)
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.corrects_claim_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_claims c WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.corrects_claim_id AND c.patient_id=NEW.patient_id AND c.association_id=NEW.association_id AND c.status='submitted'))
  THEN RAISE EXCEPTION 'Invalid insurance claim creation or source lineage' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.association_id,NEW.created_by_user_id,NEW.created_at,NEW.claim_identifier,NEW.corrects_claim_id)
     IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.association_id,OLD.created_by_user_id,OLD.created_at,OLD.claim_identifier,OLD.corrects_claim_id)
   OR OLD.status IN ('submitted','cancelled') OR NEW.lock_version<>OLD.lock_version+1 OR NEW.updated_by_user_id IS NULL
   OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.updated_by_user_id)
   OR NOT ((OLD.status='draft' AND NEW.status IN ('draft','ready','cancelled')) OR (OLD.status='ready' AND NEW.status IN ('draft','ready','submitted','cancelled')))
   OR (NEW.policy_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient_insurance_policy_revisions r WHERE r.tenant_id=NEW.tenant_id AND r.association_id=NEW.association_id AND r.id=NEW.policy_revision_id))
   OR (NEW.estimate_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM insurance_estimates e WHERE e.tenant_id=NEW.tenant_id AND e.id=NEW.estimate_id AND e.patient_id=NEW.patient_id AND (e.association_id IS NULL OR e.association_id=NEW.association_id)))
   OR (NEW.status='ready' AND (NOT EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)
       OR EXISTS(SELECT 1 FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id AND l.gross_amount<=0)
       OR (SELECT count(DISTINCT currency) FROM insurance_claim_lines l WHERE l.tenant_id=NEW.tenant_id AND l.claim_id=NEW.id)>1))
  THEN RAISE EXCEPTION 'Invalid insurance claim update or lifecycle transition' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION insurance_claim_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c insurance_claims;
BEGIN
 SELECT * INTO c FROM insurance_claims WHERE tenant_id=NEW.tenant_id AND id=NEW.claim_id;
 IF c.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id) OR NEW.version<>c.lock_version OR NEW.to_status<>c.status
  OR (NEW.event_type='created' AND (NEW.version<>1 OR NEW.from_status IS NOT NULL OR NEW.to_status<>'draft'))
  OR (NEW.event_type='validated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='ready'))
  OR (NEW.event_type='draft_updated' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='draft'))
  OR (NEW.event_type='cancelled' AND NOT (NEW.from_status IN ('draft','ready') AND NEW.to_status='cancelled'))
  OR (NEW.event_type='submitted' AND NOT (NEW.from_status='ready' AND NEW.to_status='submitted'))
 THEN RAISE EXCEPTION 'Invalid insurance claim lifecycle event' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
SQL);
    }
};
