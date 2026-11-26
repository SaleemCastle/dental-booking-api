<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE procurement_suppliers (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
                code varchar(64) NOT NULL CHECK (code ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
                name varchar(160) NOT NULL CHECK (btrim(name) <> ''),
                email varchar(254),
                phone varchar(40),
                active boolean NOT NULL DEFAULT true,
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, code)
            );

            CREATE TABLE procurement_orders (
                id bigserial PRIMARY KEY,
                tenant_id bigint NOT NULL,
                supplier_id bigint NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'draft' CHECK (status = 'draft'),
                version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
                notes varchar(1000),
                created_by_user_id bigint NOT NULL CHECK (created_by_user_id > 0),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                idempotency_key uuid NOT NULL,
                request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, idempotency_key),
                FOREIGN KEY (tenant_id, supplier_id) REFERENCES procurement_suppliers(tenant_id, id) ON DELETE RESTRICT
            );

            CREATE TABLE procurement_order_lines (
                tenant_id bigint NOT NULL,
                order_id bigint NOT NULL,
                item_id bigint NOT NULL,
                quantity numeric NOT NULL CHECK (quantity > 0 AND quantity <= 999999999999.999999 AND scale(quantity) <= 6),
                PRIMARY KEY (tenant_id, order_id, item_id),
                FOREIGN KEY (tenant_id, order_id) REFERENCES procurement_orders(tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, item_id) REFERENCES inventory_items(tenant_id, id) ON DELETE RESTRICT
            );

            CREATE INDEX procurement_orders_supplier ON procurement_orders (tenant_id, supplier_id, id);
            CREATE INDEX procurement_order_lines_item ON procurement_order_lines (tenant_id, item_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('LOCK TABLE procurement_suppliers, procurement_orders, procurement_order_lines IN ACCESS EXCLUSIVE MODE');
        if (DB::table('procurement_suppliers')->exists() || DB::table('procurement_orders')->exists()) {
            throw new RuntimeException('Procurement data exists. Preserve it and use a forward migration.');
        }

        DB::unprepared('DROP TABLE procurement_order_lines, procurement_orders, procurement_suppliers');
    }
};
