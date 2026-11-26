<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE procurement_supplier_receipts ADD CONSTRAINT supplier_receipts_supplier_unique
                UNIQUE (tenant_id, supplier_id, id);

            CREATE TABLE procurement_supplier_invoices (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                supplier_id bigint NOT NULL,
                supplier_receipt_id bigint,
                invoice_number varchar(80) NOT NULL CHECK (invoice_number ~ '^[A-Za-z0-9][ A-Za-z0-9._/#-]{0,79}$'
                    AND invoice_number = btrim(invoice_number)),
                invoice_date date NOT NULL,
                due_on date,
                currency varchar(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                reported_total numeric NOT NULL CHECK (
                    reported_total >= 0 AND reported_total <= 9999999999999999.99 AND scale(reported_total) <= 2
                ),
                recorded_by_user_id bigint NOT NULL CHECK (recorded_by_user_id > 0),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                idempotency_key uuid NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                UNIQUE (tenant_id, idempotency_key),
                FOREIGN KEY (tenant_id, supplier_id) REFERENCES procurement_suppliers(tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, supplier_id, supplier_receipt_id)
                    REFERENCES procurement_supplier_receipts(tenant_id, supplier_id, id) ON DELETE RESTRICT
            );

            CREATE UNIQUE INDEX supplier_invoice_number_unique
                ON procurement_supplier_invoices (tenant_id, supplier_id, lower(invoice_number COLLATE "C"));
            CREATE INDEX supplier_invoices_history ON procurement_supplier_invoices (tenant_id, id);
            CREATE INDEX supplier_invoices_supplier_history ON procurement_supplier_invoices (tenant_id, supplier_id, id);
            CREATE INDEX supplier_invoice_receipt ON procurement_supplier_invoices (tenant_id, supplier_id, supplier_receipt_id)
                WHERE supplier_receipt_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION supplier_invoice_guard_recorder() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM tenant_user
                    WHERE tenant_id = NEW.tenant_id AND user_id = NEW.recorded_by_user_id FOR SHARE
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23503', MESSAGE = 'Supplier invoice recorder must be a clinic member';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION supplier_invoice_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Supplier invoice observations are append-only';
            END;
            $$;

            CREATE TRIGGER supplier_invoice_recorder BEFORE INSERT ON procurement_supplier_invoices
                FOR EACH ROW EXECUTE FUNCTION supplier_invoice_guard_recorder();
            CREATE TRIGGER supplier_invoice_no_change BEFORE UPDATE OR DELETE ON procurement_supplier_invoices
                FOR EACH ROW EXECUTE FUNCTION supplier_invoice_reject_change();
            CREATE TRIGGER supplier_invoice_no_truncate BEFORE TRUNCATE ON procurement_supplier_invoices
                FOR EACH STATEMENT EXECUTE FUNCTION supplier_invoice_reject_change();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('LOCK TABLE procurement_supplier_invoices IN ACCESS EXCLUSIVE MODE');
        if (DB::table('procurement_supplier_invoices')->exists()) {
            throw new RuntimeException('Supplier invoice history exists. Preserve it and use a forward migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE procurement_supplier_invoices;
            DROP FUNCTION supplier_invoice_guard_recorder(), supplier_invoice_reject_change();
            ALTER TABLE procurement_supplier_receipts DROP CONSTRAINT supplier_receipts_supplier_unique;
            SQL);
    }
};
