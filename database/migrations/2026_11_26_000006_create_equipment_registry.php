<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE equipment_assets (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                asset_tag varchar(64) NOT NULL CHECK (asset_tag ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
                name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
                serial_number varchar(120),
                location_label varchar(160),
                status varchar(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'out_of_service', 'retired')),
                version integer NOT NULL DEFAULT 1 CHECK (version > 0),
                recorded_by_user_id bigint NOT NULL CHECK (recorded_by_user_id > 0),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, asset_tag)
            );
            CREATE INDEX equipment_assets_status_history ON equipment_assets (tenant_id, status, id);

            CREATE TABLE equipment_records (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                equipment_id bigint NOT NULL,
                kind varchar(16) NOT NULL CHECK (kind IN ('maintenance', 'warranty')),
                performed_on date,
                next_due_on date,
                starts_on date,
                ends_on date,
                provider varchar(160),
                reference varchar(120),
                details varchar(1000),
                recorded_by_user_id bigint NOT NULL CHECK (recorded_by_user_id > 0),
                recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                idempotency_key uuid NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                FOREIGN KEY (tenant_id, equipment_id) REFERENCES equipment_assets(tenant_id, id) ON DELETE RESTRICT,
                UNIQUE (tenant_id, idempotency_key),
                CHECK (
                    (kind = 'maintenance' AND performed_on IS NOT NULL
                        AND (next_due_on IS NULL OR next_due_on >= performed_on)
                        AND starts_on IS NULL AND ends_on IS NULL
                        AND details IS NOT NULL AND btrim(details) <> '')
                    OR
                    (kind = 'warranty' AND starts_on IS NOT NULL AND ends_on IS NOT NULL AND ends_on >= starts_on
                        AND performed_on IS NULL AND next_due_on IS NULL
                        AND provider IS NOT NULL AND btrim(provider) <> '')
                )
            );
            CREATE INDEX equipment_records_history ON equipment_records (tenant_id, equipment_id, kind, id);

            CREATE OR REPLACE FUNCTION equipment_check_recorder() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM tenant_user
                    WHERE tenant_id = NEW.tenant_id AND user_id = NEW.recorded_by_user_id FOR SHARE
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = '23503', MESSAGE = 'Equipment recorder must be a clinic member';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION equipment_guard_asset() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'active' OR NEW.version <> 1 THEN
                        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'New equipment must be active at version one';
                    END IF;
                ELSIF (NEW.id, NEW.tenant_id, NEW.asset_tag, NEW.recorded_by_user_id, NEW.recorded_at) IS DISTINCT FROM
                          (OLD.id, OLD.tenant_id, OLD.asset_tag, OLD.recorded_by_user_id, OLD.recorded_at)
                    OR (OLD.status = 'retired' AND NEW.status <> 'retired')
                    OR NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Invalid equipment identity, status, or version change';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION equipment_reject_history_write() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Equipment history must be preserved';
            END;
            $$;

            CREATE TRIGGER equipment_asset_recorder BEFORE INSERT ON equipment_assets
                FOR EACH ROW EXECUTE FUNCTION equipment_check_recorder();
            CREATE TRIGGER equipment_record_recorder BEFORE INSERT ON equipment_records
                FOR EACH ROW EXECUTE FUNCTION equipment_check_recorder();
            CREATE TRIGGER equipment_asset_guard BEFORE INSERT OR UPDATE ON equipment_assets
                FOR EACH ROW EXECUTE FUNCTION equipment_guard_asset();
            CREATE TRIGGER equipment_asset_no_delete BEFORE DELETE ON equipment_assets
                FOR EACH ROW EXECUTE FUNCTION equipment_reject_history_write();
            CREATE TRIGGER equipment_asset_no_truncate BEFORE TRUNCATE ON equipment_assets
                FOR EACH STATEMENT EXECUTE FUNCTION equipment_reject_history_write();
            CREATE TRIGGER equipment_record_no_change BEFORE UPDATE OR DELETE ON equipment_records
                FOR EACH ROW EXECUTE FUNCTION equipment_reject_history_write();
            CREATE TRIGGER equipment_record_no_truncate BEFORE TRUNCATE ON equipment_records
                FOR EACH STATEMENT EXECUTE FUNCTION equipment_reject_history_write();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('LOCK TABLE equipment_assets, equipment_records IN ACCESS EXCLUSIVE MODE');
        if (DB::table('equipment_assets')->exists()) {
            throw new RuntimeException('Equipment history exists. Preserve it and use a forward migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE equipment_records, equipment_assets;
            DROP FUNCTION equipment_check_recorder(), equipment_guard_asset(), equipment_reject_history_write();
            SQL);
    }
};
