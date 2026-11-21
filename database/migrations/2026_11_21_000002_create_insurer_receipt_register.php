<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurer receipt register requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE insurer_receipts (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
 insurer_id bigint NOT NULL,
 remittance_advice_id bigint,
 external_reference varchar(200),
 received_on date NOT NULL,
 recorded_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 recorded_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 channel varchar(16) NOT NULL CHECK(channel IN ('portal','email','fax','paper','phone','bank_statement','other')),
 source_provenance varchar(2000) NOT NULL CHECK(length(trim(source_provenance))>0),
 amount numeric(14,2) NOT NULL CHECK(amount>0),
 currency varchar(3) NOT NULL CHECK(currency ~ '^[A-Z]{3}$'),
 command_key uuid NOT NULL,
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,command_key),
 FOREIGN KEY(tenant_id,insurer_id) REFERENCES insurers(tenant_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(tenant_id,remittance_advice_id) REFERENCES insurance_remittance_advices(tenant_id,id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX insurer_receipt_external_reference_unique ON insurer_receipts(tenant_id,insurer_id,external_reference) WHERE external_reference IS NOT NULL;
CREATE INDEX insurer_receipt_history ON insurer_receipts(tenant_id,insurer_id,received_on DESC,id DESC);
CREATE TABLE insurer_receipt_verifications (
 id bigserial PRIMARY KEY,
 tenant_id bigint NOT NULL,
 receipt_id bigint NOT NULL,
 verified_at timestamp NOT NULL DEFAULT timezone('UTC',clock_timestamp()),
 verified_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 verification_method varchar(32) NOT NULL CHECK(verification_method IN ('bank_statement','deposit_record','insurer_confirmation','other')),
 provenance varchar(2000) NOT NULL CHECK(length(trim(provenance))>0),
 observed_amount numeric(14,2) NOT NULL CHECK(observed_amount>0),
 observed_currency varchar(3) NOT NULL CHECK(observed_currency ~ '^[A-Z]{3}$'),
 UNIQUE(tenant_id,receipt_id),
 FOREIGN KEY(tenant_id,receipt_id) REFERENCES insurer_receipts(tenant_id,id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION insurer_receipt_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Insurer receipt and verification records are immutable' USING ERRCODE='23514'; END; $$;
CREATE OR REPLACE FUNCTION insurer_receipt_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.recorded_by_user_id)
  OR (NEW.remittance_advice_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM insurance_remittance_advices a WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.remittance_advice_id AND a.insurer_id=NEW.insurer_id AND a.currency=NEW.currency))
 THEN RAISE EXCEPTION 'Invalid receipt tenant, actor, or remittance context' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION insurer_receipt_verification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR NOT EXISTS (SELECT 1 FROM tenant_user WHERE tenant_id=NEW.tenant_id AND user_id=NEW.verified_by_user_id)
  OR NOT EXISTS (SELECT 1 FROM insurer_receipts r WHERE r.tenant_id=NEW.tenant_id AND r.id=NEW.receipt_id AND r.amount=NEW.observed_amount AND r.currency=NEW.observed_currency)
 THEN RAISE EXCEPTION 'Verification must match receipt amount and currency and use a tenant member' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER insurer_receipt_insert_guard_trigger BEFORE INSERT ON insurer_receipts FOR EACH ROW EXECUTE FUNCTION insurer_receipt_insert_guard();
CREATE TRIGGER insurer_receipt_immutable BEFORE UPDATE OR DELETE ON insurer_receipts FOR EACH ROW EXECUTE FUNCTION insurer_receipt_append_only();
CREATE TRIGGER insurer_receipt_no_truncate BEFORE TRUNCATE ON insurer_receipts FOR EACH STATEMENT EXECUTE FUNCTION insurer_receipt_append_only();
CREATE TRIGGER insurer_receipt_verification_guard_trigger BEFORE INSERT ON insurer_receipt_verifications FOR EACH ROW EXECUTE FUNCTION insurer_receipt_verification_guard();
CREATE TRIGGER insurer_receipt_verification_immutable BEFORE UPDATE OR DELETE ON insurer_receipt_verifications FOR EACH ROW EXECUTE FUNCTION insurer_receipt_append_only();
CREATE TRIGGER insurer_receipt_verification_no_truncate BEFORE TRUNCATE ON insurer_receipt_verifications FOR EACH STATEMENT EXECUTE FUNCTION insurer_receipt_append_only();
SQL);
    }

    public function down(): void
    {
        if (DB::table('insurer_receipts')->exists() || DB::table('insurer_receipt_verifications')->exists()) {
            throw new RuntimeException('Insurer receipt history is immutable; populated rollback is prohibited.');
        }
        DB::unprepared('DROP TRIGGER insurer_receipt_insert_guard_trigger ON insurer_receipts; DROP TRIGGER insurer_receipt_immutable ON insurer_receipts; DROP TRIGGER insurer_receipt_no_truncate ON insurer_receipts; DROP TRIGGER insurer_receipt_verification_guard_trigger ON insurer_receipt_verifications; DROP TRIGGER insurer_receipt_verification_immutable ON insurer_receipt_verifications; DROP TRIGGER insurer_receipt_verification_no_truncate ON insurer_receipt_verifications; DROP FUNCTION insurer_receipt_insert_guard(); DROP FUNCTION insurer_receipt_verification_guard(); DROP FUNCTION insurer_receipt_append_only(); DROP TABLE insurer_receipt_verifications; DROP TABLE insurer_receipts;');
    }
};
