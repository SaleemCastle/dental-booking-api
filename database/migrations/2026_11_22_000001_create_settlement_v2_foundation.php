<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Settlement v2 foundation requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE payments ADD CONSTRAINT settlement_v2_payment_invoice_unique UNIQUE(tenant_id,id,invoice_id);

CREATE TABLE settlement_v2_payment_provenance (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 payment_id bigint NOT NULL,
 invoice_id bigint NOT NULL,
 payer_kind varchar(16) NOT NULL CHECK(payer_kind IN ('patient','insurer','other','unknown')),
 source_kind varchar(24) NOT NULL CHECK(source_kind IN ('manual','hosted_checkout','insurer_receipt','other','unknown')),
 insurer_id bigint,
 insurer_receipt_id bigint,
 recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 idempotency_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,payment_id), UNIQUE(tenant_id,idempotency_key),
 FOREIGN KEY(tenant_id,payment_id,invoice_id) REFERENCES payments(tenant_id,id,invoice_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,insurer_receipt_id) REFERENCES insurer_receipts(tenant_id,id) ON DELETE RESTRICT,
 CHECK((payer_kind='insurer') = (insurer_id IS NOT NULL)),
 CHECK((source_kind='insurer_receipt') = (insurer_receipt_id IS NOT NULL)),
 CHECK(payer_kind='insurer' OR insurer_receipt_id IS NULL)
);
CREATE UNIQUE INDEX settlement_v2_provenance_receipt_unique
 ON settlement_v2_payment_provenance(tenant_id,insurer_receipt_id)
 WHERE insurer_receipt_id IS NOT NULL;
CREATE INDEX settlement_v2_provenance_invoice_history
 ON settlement_v2_payment_provenance(tenant_id,invoice_id,id DESC);

CREATE TABLE settlement_v2_allocation_events (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 payment_id bigint NOT NULL,
 invoice_id bigint NOT NULL,
 invoice_item_id bigint NOT NULL,
 obligation_approval_id bigint,
 amount numeric(10,2) NOT NULL CHECK(amount>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 event_kind varchar(16) NOT NULL CHECK(event_kind='application'),
 recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 idempotency_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,idempotency_key),
 FOREIGN KEY(tenant_id,payment_id,invoice_id) REFERENCES payments(tenant_id,id,invoice_id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,invoice_id,invoice_item_id) REFERENCES invoice_items(tenant_id,invoice_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,obligation_approval_id) REFERENCES insurer_obligation_approvals(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX settlement_v2_allocation_invoice_line_history
 ON settlement_v2_allocation_events(tenant_id,invoice_id,invoice_item_id,id DESC);
CREATE INDEX settlement_v2_allocation_payment_history
 ON settlement_v2_allocation_events(tenant_id,payment_id,id DESC);

CREATE OR REPLACE FUNCTION settlement_v2_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Settlement v2 records are append-only' USING ERRCODE='23514'; END; $$;
CREATE OR REPLACE FUNCTION settlement_v2_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.recorded_by_user_id)
 THEN RAISE EXCEPTION 'Settlement v2 actor is not a member of the record tenant' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION settlement_v2_provenance_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.insurer_receipt_id IS NOT NULL AND NOT EXISTS(
  SELECT 1 FROM insurer_receipts WHERE tenant_id=NEW.tenant_id AND id=NEW.insurer_receipt_id AND insurer_id=NEW.insurer_id
 ) THEN RAISE EXCEPTION 'Settlement provenance receipt must belong to its recorded insurer' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION settlement_v2_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE payment_row payments; invoice_row invoices; approval_row insurer_obligation_approvals;
BEGIN
 SELECT * INTO payment_row FROM payments WHERE tenant_id=NEW.tenant_id AND id=NEW.payment_id AND invoice_id=NEW.invoice_id;
 SELECT * INTO invoice_row FROM invoices WHERE tenant_id=NEW.tenant_id AND id=NEW.invoice_id;
 IF payment_row.id IS NULL OR invoice_row.id IS NULL OR payment_row.currency IS DISTINCT FROM invoice_row.currency
  OR NEW.currency IS DISTINCT FROM invoice_row.currency
 THEN RAISE EXCEPTION 'Settlement allocation must match payment and invoice currency' USING ERRCODE='23514'; END IF;
 IF NEW.obligation_approval_id IS NOT NULL THEN
  SELECT * INTO approval_row FROM insurer_obligation_approvals
   WHERE tenant_id=NEW.tenant_id AND id=NEW.obligation_approval_id AND invoice_item_id=NEW.invoice_item_id AND currency=NEW.currency;
  IF approval_row.id IS NULL THEN RAISE EXCEPTION 'Settlement allocation obligation lineage must match invoice line and currency' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;

CREATE TRIGGER settlement_v2_provenance_insert_guard BEFORE INSERT ON settlement_v2_payment_provenance FOR EACH ROW EXECUTE FUNCTION settlement_v2_insert_guard();
CREATE TRIGGER settlement_v2_provenance_receipt_guard BEFORE INSERT ON settlement_v2_payment_provenance FOR EACH ROW EXECUTE FUNCTION settlement_v2_provenance_guard();
CREATE TRIGGER settlement_v2_provenance_immutable BEFORE UPDATE OR DELETE ON settlement_v2_payment_provenance FOR EACH ROW EXECUTE FUNCTION settlement_v2_append_only();
CREATE TRIGGER settlement_v2_provenance_no_truncate BEFORE TRUNCATE ON settlement_v2_payment_provenance FOR EACH STATEMENT EXECUTE FUNCTION settlement_v2_append_only();
CREATE TRIGGER settlement_v2_allocation_insert_guard BEFORE INSERT ON settlement_v2_allocation_events FOR EACH ROW EXECUTE FUNCTION settlement_v2_insert_guard();
CREATE TRIGGER settlement_v2_allocation_currency_guard BEFORE INSERT ON settlement_v2_allocation_events FOR EACH ROW EXECUTE FUNCTION settlement_v2_allocation_guard();
CREATE TRIGGER settlement_v2_allocation_immutable BEFORE UPDATE OR DELETE ON settlement_v2_allocation_events FOR EACH ROW EXECUTE FUNCTION settlement_v2_append_only();
CREATE TRIGGER settlement_v2_allocation_no_truncate BEFORE TRUNCATE ON settlement_v2_allocation_events FOR EACH STATEMENT EXECUTE FUNCTION settlement_v2_append_only();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Settlement v2 foundation requires PostgreSQL.');
        }
        if (DB::table('settlement_v2_payment_provenance')->exists() || DB::table('settlement_v2_allocation_events')->exists()) {
            throw new RuntimeException('Settlement v2 history is append-only; populated rollback is prohibited.');
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER settlement_v2_provenance_insert_guard ON settlement_v2_payment_provenance;
DROP TRIGGER settlement_v2_provenance_receipt_guard ON settlement_v2_payment_provenance;
DROP TRIGGER settlement_v2_provenance_immutable ON settlement_v2_payment_provenance;
DROP TRIGGER settlement_v2_provenance_no_truncate ON settlement_v2_payment_provenance;
DROP TRIGGER settlement_v2_allocation_insert_guard ON settlement_v2_allocation_events;
DROP TRIGGER settlement_v2_allocation_currency_guard ON settlement_v2_allocation_events;
DROP TRIGGER settlement_v2_allocation_immutable ON settlement_v2_allocation_events;
DROP TRIGGER settlement_v2_allocation_no_truncate ON settlement_v2_allocation_events;
DROP FUNCTION settlement_v2_insert_guard();
DROP FUNCTION settlement_v2_provenance_guard();
DROP FUNCTION settlement_v2_allocation_guard();
DROP FUNCTION settlement_v2_append_only();
DROP TABLE settlement_v2_allocation_events;
DROP TABLE settlement_v2_payment_provenance;
ALTER TABLE payments DROP CONSTRAINT settlement_v2_payment_invoice_unique;
SQL);
    }
};
