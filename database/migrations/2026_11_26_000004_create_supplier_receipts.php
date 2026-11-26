<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE procurement_supplier_receipts (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                supplier_id bigint NOT NULL,
                reference varchar(80) NOT NULL CHECK (reference ~ '^[A-Z0-9][A-Z0-9._/-]{0,79}$'),
                received_on date NOT NULL,
                recorded_by_user_id bigint NOT NULL CHECK (recorded_by_user_id > 0),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                idempotency_key uuid NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                creation_transaction bigint NOT NULL DEFAULT txid_current(),
                UNIQUE (tenant_id, id, recorded_by_user_id),
                UNIQUE (tenant_id, supplier_id, reference),
                UNIQUE (tenant_id, idempotency_key),
                FOREIGN KEY (tenant_id, supplier_id) REFERENCES procurement_suppliers(tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX supplier_receipts_history ON procurement_supplier_receipts (tenant_id, id);

            ALTER TABLE inventory_movements ADD COLUMN supplier_receipt_id bigint,
                ADD CONSTRAINT inventory_supplier_receipt_actor_fk
                    FOREIGN KEY (tenant_id, supplier_receipt_id, recorded_by_user_id)
                    REFERENCES procurement_supplier_receipts(tenant_id, id, recorded_by_user_id) ON DELETE RESTRICT,
                ADD CONSTRAINT inventory_supplier_receipt_kind CHECK (supplier_receipt_id IS NULL OR kind = 'receipt');
            CREATE INDEX inventory_supplier_receipt_lines ON inventory_movements (tenant_id, supplier_receipt_id, id)
                WHERE supplier_receipt_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION supplier_receipt_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Supplier receipt history is append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION inventory_check_supplier_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Only the transaction creating the header can attach movement lines.
                IF NOT EXISTS (
                    SELECT 1 FROM procurement_supplier_receipts
                    WHERE tenant_id = NEW.tenant_id AND id = NEW.supplier_receipt_id
                        AND recorded_by_user_id = NEW.recorded_by_user_id
                        AND creation_transaction = txid_current()
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Supplier receipt lines must be recorded with their header';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION supplier_receipt_require_lines() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM inventory_movements WHERE tenant_id = NEW.tenant_id AND supplier_receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'A supplier receipt must contain stock movements';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER supplier_receipt_no_change BEFORE UPDATE OR DELETE ON procurement_supplier_receipts
                FOR EACH ROW EXECUTE FUNCTION supplier_receipt_reject_change();
            CREATE TRIGGER supplier_receipt_no_truncate BEFORE TRUNCATE ON procurement_supplier_receipts
                FOR EACH STATEMENT EXECUTE FUNCTION supplier_receipt_reject_change();
            CREATE TRIGGER inventory_supplier_receipt_check BEFORE INSERT ON inventory_movements
                FOR EACH ROW WHEN (NEW.supplier_receipt_id IS NOT NULL)
                EXECUTE FUNCTION inventory_check_supplier_receipt();
            CREATE CONSTRAINT TRIGGER supplier_receipt_with_lines AFTER INSERT ON procurement_supplier_receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION supplier_receipt_require_lines();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('LOCK TABLE inventory_movements, procurement_supplier_receipts IN ACCESS EXCLUSIVE MODE');
        if (DB::table('procurement_supplier_receipts')->exists()) {
            throw new RuntimeException('Supplier receipt history exists. Preserve it and use a forward migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER inventory_supplier_receipt_check ON inventory_movements;
            ALTER TABLE inventory_movements DROP COLUMN supplier_receipt_id;
            DROP TABLE procurement_supplier_receipts;
            DROP FUNCTION inventory_check_supplier_receipt(), supplier_receipt_reject_change(), supplier_receipt_require_lines();
            SQL);
    }
};
