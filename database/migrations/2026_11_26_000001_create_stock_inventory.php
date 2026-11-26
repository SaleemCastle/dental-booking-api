<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE inventory_items (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                sku varchar(64) NOT NULL CHECK (sku ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
                name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
                base_unit varchar(32) NOT NULL CHECK (base_unit ~ '^[a-z][a-z0-9_-]{0,31}$'),
                movement_version bigint NOT NULL DEFAULT 0,
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, sku)
            );

            CREATE TABLE inventory_locations (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                code varchar(64) NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
                name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, code)
            );

            CREATE TABLE inventory_lots (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                item_id bigint NOT NULL,
                code varchar(64) NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
                expires_on date,
                UNIQUE (tenant_id, item_id, id),
                UNIQUE (tenant_id, item_id, code),
                FOREIGN KEY (tenant_id, item_id) REFERENCES inventory_items(tenant_id, id) ON DELETE RESTRICT
            );

            CREATE TABLE inventory_movements (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                item_id bigint NOT NULL,
                location_id bigint NOT NULL,
                lot_id bigint,
                kind varchar(12) NOT NULL CHECK (kind IN ('receipt', 'issue', 'adjustment', 'correction')),
                quantity_delta numeric NOT NULL CHECK (
                    quantity_delta <> 0 AND abs(quantity_delta) <= 999999999999.999999
                    AND scale(quantity_delta) <= 6
                ),
                reason varchar(500) NOT NULL CHECK (btrim(reason) <> ''),
                corrects_movement_id bigint,
                recorded_by_user_id bigint NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                idempotency_key uuid NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                UNIQUE (tenant_id, item_id, id),
                UNIQUE (tenant_id, item_id, idempotency_key),
                UNIQUE (tenant_id, item_id, corrects_movement_id),
                FOREIGN KEY (tenant_id, item_id) REFERENCES inventory_items(tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations(tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, item_id, lot_id) REFERENCES inventory_lots(tenant_id, item_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, item_id, corrects_movement_id) REFERENCES inventory_movements(tenant_id, item_id, id) ON DELETE RESTRICT,
                CHECK ((kind = 'correction') = (corrects_movement_id IS NOT NULL)),
                CHECK ((kind <> 'receipt' OR quantity_delta > 0) AND (kind <> 'issue' OR quantity_delta < 0))
            );

            CREATE INDEX inventory_movement_bucket ON inventory_movements (tenant_id, item_id, location_id, lot_id);
            CREATE INDEX inventory_movement_history ON inventory_movements (tenant_id, id);

            CREATE OR REPLACE FUNCTION inventory_guard_movement() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                original inventory_movements%ROWTYPE;
                available numeric;
            BEGIN
                -- A write, rather than only a row lock, also forces a stale
                -- REPEATABLE READ transaction to retry before reading the ledger.
                UPDATE inventory_items SET movement_version = movement_version + 1
                WHERE tenant_id = NEW.tenant_id AND id = NEW.item_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION USING ERRCODE = '23503', MESSAGE = 'Unknown clinic inventory item';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM tenant_user WHERE tenant_id = NEW.tenant_id AND user_id = NEW.recorded_by_user_id FOR SHARE
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23503', MESSAGE = 'Inventory recorder must be a clinic member';
                END IF;

                IF NEW.kind = 'correction' THEN
                    SELECT * INTO original FROM inventory_movements
                    WHERE tenant_id = NEW.tenant_id AND item_id = NEW.item_id AND id = NEW.corrects_movement_id;
                    IF NOT FOUND OR original.kind = 'correction' OR original.location_id <> NEW.location_id
                        OR original.lot_id IS DISTINCT FROM NEW.lot_id OR original.quantity_delta <> -NEW.quantity_delta THEN
                        RAISE EXCEPTION USING ERRCODE = 'P5102', MESSAGE = 'Invalid inventory correction lineage';
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM inventory_movements WHERE tenant_id = NEW.tenant_id AND item_id = NEW.item_id
                        AND corrects_movement_id = NEW.corrects_movement_id
                    ) THEN
                        RAISE EXCEPTION USING ERRCODE = 'P5102', MESSAGE = 'Inventory movement already corrected';
                    END IF;
                END IF;

                SELECT COALESCE(SUM(quantity_delta), 0) INTO available FROM inventory_movements
                WHERE tenant_id = NEW.tenant_id AND item_id = NEW.item_id AND location_id = NEW.location_id
                    AND lot_id IS NOT DISTINCT FROM NEW.lot_id;
                IF available + NEW.quantity_delta < 0 THEN
                    RAISE EXCEPTION USING ERRCODE = 'P5101', MESSAGE = 'Insufficient stock in this location and lot';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION inventory_reject_history_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Inventory movement history is append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION inventory_guard_catalog_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'inventory_items' THEN
                    IF (NEW.tenant_id, NEW.id, NEW.sku, NEW.base_unit) IS DISTINCT FROM
                        (OLD.tenant_id, OLD.id, OLD.sku, OLD.base_unit) THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Inventory item identity and unit are fixed';
                    END IF;
                ELSIF NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Stock location and lot definitions are fixed';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER inventory_check_insert BEFORE INSERT ON inventory_movements
                FOR EACH ROW EXECUTE FUNCTION inventory_guard_movement();
            CREATE TRIGGER inventory_history_immutable BEFORE UPDATE OR DELETE ON inventory_movements
                FOR EACH ROW EXECUTE FUNCTION inventory_reject_history_change();
            CREATE TRIGGER inventory_history_no_truncate BEFORE TRUNCATE ON inventory_movements
                FOR EACH STATEMENT EXECUTE FUNCTION inventory_reject_history_change();
            CREATE TRIGGER inventory_item_identity BEFORE UPDATE ON inventory_items
                FOR EACH ROW EXECUTE FUNCTION inventory_guard_catalog_identity();
            CREATE TRIGGER inventory_location_identity BEFORE UPDATE ON inventory_locations
                FOR EACH ROW EXECUTE FUNCTION inventory_guard_catalog_identity();
            CREATE TRIGGER inventory_lot_identity BEFORE UPDATE ON inventory_lots
                FOR EACH ROW EXECUTE FUNCTION inventory_guard_catalog_identity();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('inventory_movements')->exists()) {
            throw new RuntimeException('Inventory movement history exists. Preserve the ledger and use a forward migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE inventory_movements, inventory_lots, inventory_locations, inventory_items;
            DROP FUNCTION inventory_guard_movement(), inventory_reject_history_change(), inventory_guard_catalog_identity();
            SQL);
    }
};
