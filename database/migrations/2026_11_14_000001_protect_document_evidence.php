<?php

use App\Support\MigrationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = ['document_series', 'document_versions', 'document_protections', 'document_hold_events', 'document_evidence_links'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurance evidence protection requires PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE patient ADD CONSTRAINT evidence_patient_tenant_unique UNIQUE (tenant_id, id);
ALTER TABLE patient_documents ADD CONSTRAINT evidence_document_tenant_unique UNIQUE (tenant_id, id);
ALTER TABLE patient_documents ALTER COLUMN patient_id DROP NOT NULL;
CREATE TABLE document_series (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 patient_id bigint, owner_scope varchar(10) NOT NULL CHECK (owner_scope IN ('patient','tenant')),
 classification varchar(10) NOT NULL CHECK (classification IN ('clinical','insurance','mixed')),
 lock_version integer NOT NULL DEFAULT 1 CHECK (lock_version > 0), created_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE (tenant_id,id), FOREIGN KEY (tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 CHECK ((owner_scope='patient' AND patient_id IS NOT NULL) OR (owner_scope='tenant' AND patient_id IS NULL)),
 CHECK (owner_scope='patient' OR classification='insurance')
);
CREATE TABLE document_versions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 series_id bigint NOT NULL, document_id bigint NOT NULL UNIQUE, version integer NOT NULL CHECK (version>0),
 predecessor_id bigint, content_sha256 varchar(64), scan_status varchar(24) NOT NULL,
 scanner_reference varchar(255), provenance text NOT NULL, command_key uuid NOT NULL, request_hash varchar(64) NOT NULL, recorded_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE (tenant_id,id), UNIQUE (tenant_id,command_key), UNIQUE (tenant_id,series_id,id), UNIQUE (tenant_id,series_id,version),
 FOREIGN KEY (tenant_id,series_id) REFERENCES document_series(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (tenant_id,document_id) REFERENCES patient_documents(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (tenant_id,series_id,predecessor_id) REFERENCES document_versions(tenant_id,series_id,id) ON DELETE RESTRICT,
 CHECK (content_sha256 IS NULL OR content_sha256 ~ '^[a-f0-9]{64}$'),
 CHECK (scan_status IN ('pending','clean','quarantined','legacy_unverified')),
 CHECK (scan_status <> 'clean' OR (content_sha256 IS NOT NULL AND scanner_reference IS NOT NULL AND LENGTH(TRIM(scanner_reference))>0))
);
CREATE TABLE document_protections (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 version_id bigint NOT NULL UNIQUE, quarantined boolean NOT NULL DEFAULT false,
 purge_state varchar(24) NOT NULL DEFAULT 'retained' CHECK (purge_state IN ('retained','purge_in_progress','purge_failed','purged')),
 lock_version integer NOT NULL DEFAULT 1 CHECK (lock_version>0),
 FOREIGN KEY (tenant_id,version_id) REFERENCES document_versions(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE document_hold_events (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 series_id bigint NOT NULL, action varchar(10) NOT NULL CHECK (action IN ('placed','released')),
 release_of_id bigint UNIQUE, reason text NOT NULL, actor_id bigint NOT NULL,
 command_key uuid NOT NULL, created_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE (tenant_id,series_id,id), UNIQUE (tenant_id,command_key),
 FOREIGN KEY (tenant_id,series_id) REFERENCES document_series(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (tenant_id,series_id,release_of_id) REFERENCES document_hold_events(tenant_id,series_id,id) ON DELETE RESTRICT,
 CHECK ((action='placed' AND release_of_id IS NULL) OR (action='released' AND release_of_id IS NOT NULL))
);
CREATE TABLE document_evidence_links (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 version_id bigint NOT NULL, patient_id bigint, plan_version_id bigint,
 actor_id bigint NOT NULL, command_key uuid NOT NULL, created_at timestamp NOT NULL DEFAULT timezone('UTC',CURRENT_TIMESTAMP),
 UNIQUE (tenant_id,command_key),
 FOREIGN KEY (tenant_id,version_id) REFERENCES document_versions(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (tenant_id,patient_id) REFERENCES patient(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (tenant_id,plan_version_id) REFERENCES insurance_plan_versions(tenant_id,id) ON DELETE RESTRICT,
 CHECK ((patient_id IS NOT NULL AND plan_version_id IS NULL) OR (patient_id IS NULL AND plan_version_id IS NOT NULL))
);
CREATE UNIQUE INDEX evidence_patient_link_unique ON document_evidence_links(tenant_id,version_id,patient_id) WHERE patient_id IS NOT NULL;
CREATE UNIQUE INDEX evidence_plan_link_unique ON document_evidence_links(tenant_id,version_id,plan_version_id) WHERE plan_version_id IS NOT NULL;
CREATE INDEX evidence_series_browse ON document_series(tenant_id,owner_scope,patient_id,id);
CREATE INDEX evidence_holds_browse ON document_hold_events(tenant_id,series_id,action,id);
CREATE INDEX evidence_links_browse ON document_evidence_links(tenant_id,version_id,id);
-- No bytes are read or invented during migration. Historical hashes remain unavailable.
INSERT INTO document_series(id,tenant_id,patient_id,owner_scope,classification)
 SELECT id,tenant_id,patient_id,'patient','clinical' FROM patient_documents;
INSERT INTO document_versions(id,tenant_id,series_id,document_id,version,scan_status,provenance,command_key,request_hash)
 SELECT id,tenant_id,id,id,1,'legacy_unverified','Legacy metadata imported; bytes and checksum not observed',md5('legacy-document:'||tenant_id||':'||id)::uuid,repeat('0',64) FROM patient_documents;
INSERT INTO document_protections(tenant_id,version_id,purge_state)
 SELECT tenant_id,id,CASE WHEN purged_at IS NOT NULL THEN 'purged' WHEN deleted_at IS NOT NULL THEN 'purge_failed' ELSE 'retained' END FROM patient_documents;
SELECT setval(pg_get_serial_sequence('document_series','id'), COALESCE((SELECT MAX(id) FROM document_series),1), EXISTS(SELECT 1 FROM document_series));
SELECT setval(pg_get_serial_sequence('document_versions','id'), COALESCE((SELECT MAX(id) FROM document_versions),1), EXISTS(SELECT 1 FROM document_versions));
CREATE OR REPLACE FUNCTION evidence_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Document evidence history is immutable' USING ERRCODE='23514'; END; $$;
CREATE OR REPLACE FUNCTION evidence_series_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.id,NEW.tenant_id,NEW.patient_id,NEW.owner_scope,NEW.classification,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.patient_id,OLD.owner_scope,OLD.classification,OLD.created_at)
 OR NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Document series identity is immutable' USING ERRCODE='23514'; END IF; RETURN NEW;
END; $$;
CREATE TRIGGER evidence_series_update BEFORE UPDATE ON document_series FOR EACH ROW EXECUTE FUNCTION evidence_series_guard();
CREATE OR REPLACE FUNCTION evidence_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s document_series; d patient_documents; p document_versions;
BEGIN
 SELECT * INTO s FROM document_series WHERE id=NEW.series_id AND tenant_id=NEW.tenant_id FOR UPDATE;
 SELECT * INTO d FROM patient_documents WHERE id=NEW.document_id AND tenant_id=NEW.tenant_id;
 IF s.id IS NULL OR d.id IS NULL OR s.patient_id IS DISTINCT FROM d.patient_id OR d.deleted_at IS NOT NULL OR d.purged_at IS NOT NULL OR EXISTS(SELECT 1 FROM document_versions older JOIN patient_documents stored ON stored.id=older.document_id WHERE older.series_id=NEW.series_id AND stored.deleted_at IS NOT NULL)
 THEN RAISE EXCEPTION 'Invalid document version ownership' USING ERRCODE='23514'; END IF;
 IF NEW.predecessor_id IS NULL THEN
  IF NEW.version<>1 THEN RAISE EXCEPTION 'Invalid first document version' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO p FROM document_versions WHERE id=NEW.predecessor_id AND tenant_id=NEW.tenant_id AND series_id=NEW.series_id;
  IF p.id IS NULL OR NEW.version<>p.version+1 THEN RAISE EXCEPTION 'Invalid document predecessor' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER evidence_version_insert BEFORE INSERT ON document_versions FOR EACH ROW EXECUTE FUNCTION evidence_version_guard();
CREATE OR REPLACE FUNCTION evidence_retained(v bigint) RETURNS boolean LANGUAGE sql VOLATILE AS $$
 SELECT s.classification<>'clinical' OR EXISTS(SELECT 1 FROM document_protections p WHERE p.version_id=dv.id AND p.quarantined) OR EXISTS(SELECT 1 FROM document_evidence_links l JOIN document_versions linked ON linked.id=l.version_id WHERE linked.series_id=s.id)
 OR EXISTS(SELECT 1 FROM document_hold_events h WHERE h.series_id=s.id AND h.action='placed' AND NOT EXISTS(SELECT 1 FROM document_hold_events r WHERE r.release_of_id=h.id))
 FROM document_versions dv JOIN document_series s ON s.id=dv.series_id WHERE dv.id=v
$$;
CREATE OR REPLACE FUNCTION evidence_protection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v document_versions; d patient_documents;
BEGIN
 SELECT * INTO v FROM document_versions WHERE id=NEW.version_id AND tenant_id=NEW.tenant_id;
 IF v.id IS NULL THEN RAISE EXCEPTION 'Missing document version' USING ERRCODE='23514'; END IF;
 UPDATE document_series SET lock_version=lock_version+1 WHERE id=v.series_id;
 IF TG_OP='INSERT' THEN
  IF NEW.purge_state<>'retained' OR NEW.lock_version<>1 OR (v.scan_status='quarantined' AND NOT NEW.quarantined) OR
   (EXISTS(SELECT 1 FROM document_series s WHERE s.id=v.series_id AND s.classification<>'clinical') AND v.scan_status<>'clean' AND NOT NEW.quarantined)
  THEN RAISE EXCEPTION 'Invalid initial document protection' USING ERRCODE='23514'; END IF;
 ELSE
  IF ROW(NEW.id,NEW.tenant_id,NEW.version_id) IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.version_id) OR NEW.lock_version<>OLD.lock_version+1 OR (OLD.quarantined AND NOT NEW.quarantined)
  THEN RAISE EXCEPTION 'Document protection cannot be removed' USING ERRCODE='23514'; END IF;
  IF NEW.purge_state IS DISTINCT FROM OLD.purge_state THEN
   IF NOT ((OLD.purge_state IN ('retained','purge_failed') AND NEW.purge_state='purge_in_progress') OR (OLD.purge_state='purge_in_progress' AND NEW.purge_state IN ('purge_failed','purged')))
   OR evidence_retained(v.id) THEN RAISE EXCEPTION 'Document retention or purge fence violation' USING ERRCODE='23514'; END IF;
   SELECT * INTO d FROM patient_documents WHERE id=v.document_id;
   IF d.deleted_at IS NULL THEN RAISE EXCEPTION 'Purge requires access revocation' USING ERRCODE='23514'; END IF;
  END IF;
 END IF; RETURN NEW;
END; $$;
CREATE TRIGGER evidence_protection_insert BEFORE INSERT ON document_protections FOR EACH ROW EXECUTE FUNCTION evidence_protection_guard();
CREATE TRIGGER evidence_protection_update BEFORE UPDATE ON document_protections FOR EACH ROW EXECUTE FUNCTION evidence_protection_guard();
CREATE OR REPLACE FUNCTION evidence_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE sid bigint; v document_versions; d patient_documents; s document_series;
BEGIN
 IF TG_TABLE_NAME='document_hold_events' THEN sid:=NEW.series_id;
 ELSE SELECT * INTO v FROM document_versions WHERE id=NEW.version_id AND tenant_id=NEW.tenant_id; sid:=v.series_id; END IF;
 UPDATE document_series SET lock_version=lock_version+1 WHERE id=sid AND tenant_id=NEW.tenant_id RETURNING * INTO s;
 IF s.id IS NULL OR NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.actor_id)
 THEN RAISE EXCEPTION 'Invalid evidence actor or owner' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM document_versions dv JOIN patient_documents pd ON pd.id=dv.document_id JOIN document_protections dp ON dp.version_id=dv.id WHERE dv.series_id=sid AND (pd.deleted_at IS NOT NULL OR dp.purge_state<>'retained'))
 THEN RAISE EXCEPTION 'Document purge fence rejects late hold or link' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='document_hold_events' THEN
  IF NEW.action='released' AND NOT EXISTS(SELECT 1 FROM document_hold_events WHERE id=NEW.release_of_id AND series_id=sid AND tenant_id=NEW.tenant_id AND action='placed')
  THEN RAISE EXCEPTION 'Invalid hold release' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO d FROM patient_documents WHERE id=v.document_id;
  IF v.scan_status<>'clean' OR v.content_sha256 IS NULL OR d.deleted_at IS NOT NULL OR EXISTS(SELECT 1 FROM document_protections WHERE version_id=v.id AND quarantined)
   OR (NEW.patient_id IS NOT NULL AND s.patient_id IS DISTINCT FROM NEW.patient_id)
   OR (NEW.plan_version_id IS NOT NULL AND s.owner_scope<>'tenant')
  THEN RAISE EXCEPTION 'Evidence must be clean and match its target owner' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END; $$;
CREATE TRIGGER evidence_hold_insert BEFORE INSERT ON document_hold_events FOR EACH ROW EXECUTE FUNCTION evidence_reference_guard();
CREATE TRIGGER evidence_link_insert BEFORE INSERT ON document_evidence_links FOR EACH ROW EXECUTE FUNCTION evidence_reference_guard();
CREATE OR REPLACE FUNCTION evidence_document_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v document_versions;
BEGIN
 SELECT * INTO v FROM document_versions WHERE document_id=OLD.id;
 IF v.id IS NULL THEN RAISE EXCEPTION 'Document version protection missing' USING ERRCODE='23514'; END IF;
 IF NEW.deleted_at IS DISTINCT FROM OLD.deleted_at OR NEW.purged_at IS DISTINCT FROM OLD.purged_at THEN
  UPDATE document_series SET lock_version=lock_version+1 WHERE id=v.series_id;
  IF evidence_retained(v.id) THEN RAISE EXCEPTION 'Document retention blocks deletion' USING ERRCODE='23514'; END IF;
  IF NEW.purged_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM document_protections WHERE version_id=v.id AND purge_state='purge_in_progress') THEN RAISE EXCEPTION 'Missing purge execution fence' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END; $$;
CREATE TRIGGER evidence_document_update BEFORE UPDATE ON patient_documents FOR EACH ROW EXECUTE FUNCTION evidence_document_barrier();
CREATE OR REPLACE FUNCTION evidence_require_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF NOT EXISTS(SELECT 1 FROM document_versions v JOIN document_protections p ON p.version_id=v.id WHERE v.document_id=NEW.id AND v.tenant_id=NEW.tenant_id) THEN RAISE EXCEPTION 'Document version protection required' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$;
CREATE CONSTRAINT TRIGGER evidence_document_insert AFTER INSERT ON patient_documents DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION evidence_require_version();
SQL);
        foreach (['document_versions', 'document_hold_events', 'document_evidence_links'] as $table) {
            DB::unprepared("CREATE TRIGGER evidence_immutable_update BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION evidence_append_only()");
        }
        foreach ([...$this->tables, 'patient_documents'] as $table) {
            DB::unprepared("CREATE TRIGGER evidence_immutable_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION evidence_append_only()");
            DB::unprepared("CREATE TRIGGER evidence_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION evidence_append_only()");
        }
        $this->documentGuards(true);
    }

    private function documentGuards(bool $tenantDocuments): void
    {
        foreach (['insert', 'update'] as $event) {
            MigrationGuard::drop('patient_documents_guard_'.$event, 'patient_documents');
            $checks = [
                'NEW.tenant_id IS NULL', 'NEW.size_bytes < 1',
                '(NEW.archived_at IS NULL) <> (NEW.archived_by_user_id IS NULL)',
                '(NEW.deleted_at IS NULL) <> (NEW.deleted_by_user_id IS NULL)',
                '(NEW.purged_at IS NOT NULL AND NEW.deleted_at IS NULL)',
                $tenantDocuments ? '(NEW.patient_id IS NULL AND (NEW.appointment_id IS NOT NULL OR NEW.clinical_note_id IS NOT NULL))' : 'NEW.patient_id IS NULL',
                '(NEW.patient_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM patient WHERE id=NEW.patient_id AND tenant_id=NEW.tenant_id))',
                '(NEW.appointment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM appointment WHERE id=NEW.appointment_id AND tenant_id=NEW.tenant_id AND patient_id=NEW.patient_id))',
                '(NEW.clinical_note_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM clinical_notes WHERE id=NEW.clinical_note_id AND tenant_id=NEW.tenant_id AND patient_id=NEW.patient_id))',
                '(NEW.appointment_id IS NOT NULL AND NEW.clinical_note_id IS NOT NULL AND EXISTS(SELECT 1 FROM clinical_notes WHERE id=NEW.clinical_note_id AND appointment_id IS NOT NULL AND appointment_id<>NEW.appointment_id))',
            ];
            foreach (['uploaded_by_user_id', 'archived_by_user_id', 'deleted_by_user_id'] as $column) {
                $changed = $event === 'insert' ? 'true' : "(OLD.$column IS NULL OR NEW.$column IS DISTINCT FROM OLD.$column)";
                $checks[] = "(NEW.$column IS NOT NULL AND $changed AND NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.$column))";
            }
            if ($event === 'update') {
                foreach (['archived_at', 'archived_by_user_id', 'deleted_at', 'deleted_by_user_id', 'purged_at'] as $column) {
                    $checks[] = "(OLD.$column IS NOT NULL AND NEW.$column IS DISTINCT FROM OLD.$column)";
                }
                foreach (['tenant_id', 'patient_id', 'appointment_id', 'clinical_note_id', 'disk', 'storage_key', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by_user_id', 'created_at'] as $column) {
                    $checks[] = "NEW.$column IS DISTINCT FROM OLD.$column";
                }
            }
            MigrationGuard::create('patient_documents_guard_'.$event, 'patient_documents', $event, implode(' OR ', $checks), 'Invalid patient document ownership or metadata');
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Preserve protected document history; populated rollback is prohibited.');
            }
        }
        foreach (['evidence_document_update', 'evidence_document_insert', 'evidence_immutable_delete', 'evidence_no_truncate'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS $trigger ON patient_documents");
        }
        foreach (array_reverse($this->tables) as $table) {
            DB::unprepared("DROP TABLE $table");
        }
        foreach (['evidence_require_version', 'evidence_document_barrier', 'evidence_reference_guard', 'evidence_protection_guard', 'evidence_retained(bigint)', 'evidence_version_guard', 'evidence_series_guard', 'evidence_append_only'] as $function) {
            DB::unprepared('DROP FUNCTION IF EXISTS '.(str_contains($function, '(') ? $function : $function.'()'));
        }
        $this->documentGuards(false);
        DB::unprepared('ALTER TABLE patient_documents ALTER COLUMN patient_id SET NOT NULL; ALTER TABLE patient_documents DROP CONSTRAINT evidence_document_tenant_unique; ALTER TABLE patient DROP CONSTRAINT evidence_patient_tenant_unique');
    }
};
