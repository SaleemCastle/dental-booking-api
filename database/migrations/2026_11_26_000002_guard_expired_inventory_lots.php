<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory_check_issue_expiry() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM inventory_lots
                    WHERE tenant_id = NEW.tenant_id AND item_id = NEW.item_id AND id = NEW.lot_id
                    AND expires_on < timezone('UTC', clock_timestamp())::date
                ) THEN
                    RAISE EXCEPTION USING ERRCODE = 'P5103', MESSAGE = 'Expired inventory lots cannot be issued';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER inventory_issue_expiry BEFORE INSERT ON inventory_movements
                FOR EACH ROW WHEN (NEW.kind = 'issue' AND NEW.lot_id IS NOT NULL)
                EXECUTE FUNCTION inventory_check_issue_expiry();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER inventory_issue_expiry ON inventory_movements;
            DROP FUNCTION inventory_check_issue_expiry();
            SQL);
    }
};
