<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dépôts et stocks (docs/03 §6, L2-06, L2-07). Les invariants de stock sont garantis en base :
 * compartiments jamais négatifs, disponible jamais négatif, mouvements immuables et uniques par origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 16);
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->string('status', 16)->default('active');
            $table->auditColumns();

            $table->unique(['company_id', 'code'], 'uq_wh_code');
            $table->unique(['company_id', 'name'], 'uq_wh_name');
        });
        Constraints::check('warehouses', 'ck_wh_status', "status IN ('active','inactive')");

        Schema::table('company_settings', function (Blueprint $table) {
            $table->foreign(['company_id', 'default_sales_warehouse_id'], 'fk_cs_wh')->references(['company_id', 'id'])->on('warehouses');
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('lot_number', 40);
            $table->ulidColumn('variant_id');
            $table->string('origin_type', 16);
            $table->ulidColumn('origin_id')->nullable();
            // FK vers shipments et landed_cost_calculations ajoutées au lot 3.
            $table->ulidColumn('shipment_id')->nullable();
            $table->timestamp('received_at', 6);
            $table->quantity('initial_qty');
            $table->unitCost('unit_cost');
            $table->currencyCode('cost_currency');
            $table->string('cost_status', 16);
            $table->ulidColumn('landed_cost_calculation_id')->nullable();
            $table->json('cost_breakdown')->nullable();
            $table->auditColumns();

            $table->tenantForeign('variant_id', 'product_variants', 'fk_lot_variant');
            $table->foreign('cost_currency', 'fk_lot_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'lot_number'], 'uq_lot_number');
            $table->index(['company_id', 'variant_id', 'received_at'], 'ix_lot_fifo');
            $table->index(['company_id', 'shipment_id'], 'ix_lot_shipment');
        });
        Constraints::check('stock_lots', 'ck_lot_qty', 'initial_qty > 0');
        Constraints::check('stock_lots', 'ck_lot_cost', 'unit_cost >= 0');
        Constraints::check('stock_lots', 'ck_lot_origin', "origin_type IN ('receipt','opening','adjustment')");
        Constraints::check('stock_lots', 'ck_lot_cost_status', "cost_status IN ('provisional','final')");
        // CR-004 : le coût d'un lot ne change qu'au passage de provisoire à définitif.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_stock_lots_cost BEFORE UPDATE ON stock_lots
            FOR EACH ROW
            BEGIN
                IF NEW.variant_id <> OLD.variant_id OR NEW.initial_qty <> OLD.initial_qty OR NEW.company_id <> OLD.company_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lot de stock : variante et quantité initiale immuables';
                END IF;
                IF NEW.unit_cost <> OLD.unit_cost AND NOT (OLD.cost_status = 'provisional' AND NEW.cost_status = 'final') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lot de stock : coût modifiable uniquement au passage de provisoire à définitif';
                END IF;
            END
            SQL);

        Schema::create('stock_positions', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('variant_id');
            $table->quantity('qty_on_hand')->default(0);
            $table->quantity('qty_reserved')->default(0);
            $table->quantity('qty_blocked')->default(0);
            $table->quantity('qty_in_delivery')->default(0);
            $table->timestamp('last_movement_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_pos_wh');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_pos_variant');
            $table->unique(['company_id', 'warehouse_id', 'variant_id'], 'uq_pos');
            $table->index(['company_id', 'variant_id'], 'ix_pos_variant');
        });
        DB::statement('ALTER TABLE stock_positions ADD COLUMN qty_available BIGINT AS (qty_on_hand - qty_reserved - qty_blocked - qty_in_delivery) STORED AFTER qty_in_delivery');
        DB::statement('ALTER TABLE stock_positions ADD INDEX ix_pos_available (company_id, warehouse_id, qty_available)');
        Constraints::check('stock_positions', 'ck_pos_nonneg', 'qty_on_hand >= 0 AND qty_reserved >= 0 AND qty_blocked >= 0 AND qty_in_delivery >= 0');
        Constraints::check('stock_positions', 'ck_pos_available', 'qty_on_hand - qty_reserved - qty_blocked - qty_in_delivery >= 0');

        Schema::create('stock_lot_balances', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('lot_id');
            $table->ulidColumn('variant_id');
            $table->quantity('qty_on_hand')->default(0);
            $table->quantity('qty_blocked')->default(0);
            $table->quantity('qty_in_delivery')->default(0);
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_lotbal_wh');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_lotbal_lot');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_lotbal_variant');
            $table->unique(['company_id', 'warehouse_id', 'lot_id'], 'uq_lotbal');
            $table->index(['company_id', 'warehouse_id', 'variant_id', 'qty_on_hand'], 'ix_lotbal_fifo');
        });
        Constraints::check('stock_lot_balances', 'ck_lotbal_nonneg', 'qty_on_hand >= 0 AND qty_blocked >= 0 AND qty_in_delivery >= 0');
        Constraints::check('stock_lot_balances', 'ck_lotbal_available', 'qty_on_hand - qty_blocked - qty_in_delivery >= 0');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('lot_id');
            $table->string('movement_type', 24);
            $table->quantity('quantity');
            $table->unitCost('unit_cost');
            $table->money('total_cost');
            $table->string('source_type', 32);
            $table->ulidColumn('source_id');
            $table->ulidColumn('source_line_id');
            $table->unsignedSmallInteger('reversal_seq')->default(0);
            $table->ulidColumn('reverses_movement_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('created_at', 6)->useCurrent();
            $table->ulidColumn('created_by');

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_mov_wh');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_mov_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_mov_lot');
            $table->tenantForeign('reverses_movement_id', 'stock_movements', 'fk_mov_reverses');
            // Anti-doublon : une origine ne produit jamais deux fois le même mouvement (IMP-004, TEST-008).
            $table->unique(['company_id', 'source_type', 'source_line_id', 'movement_type', 'lot_id', 'reversal_seq'], 'uq_mov_source');
            $table->index(['company_id', 'variant_id', 'occurred_at'], 'ix_mov_variant_time');
            $table->index(['company_id', 'warehouse_id', 'occurred_at'], 'ix_mov_wh_time');
            $table->index(['company_id', 'lot_id'], 'ix_mov_lot');
            $table->index(['company_id', 'source_type', 'source_id'], 'ix_mov_source');
        });
        Constraints::check('stock_movements', 'ck_mov_qty', 'quantity <> 0');
        Constraints::check('stock_movements', 'ck_mov_type', "movement_type IN ('receipt','sale_delivery','return_in','transfer_in','transfer_out','adjustment_in','adjustment_out','loss','opening','reversal')");
        Constraints::appendOnly('stock_movements');

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('variant_id');
            $table->string('source_type', 32);
            $table->ulidColumn('source_id');
            $table->quantity('qty_reserved');
            $table->quantity('qty_consumed')->default(0);
            $table->quantity('qty_released')->default(0);
            $table->boolean('is_provisional')->default(false);
            $table->string('status', 16)->default('active');
            $table->auditColumns();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_resa_wh');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_resa_variant');
            $table->unique(['company_id', 'source_type', 'source_id'], 'uq_resa_source');
            $table->index(['company_id', 'warehouse_id', 'variant_id', 'status'], 'ix_resa_pos');
        });
        DB::statement('ALTER TABLE stock_reservations ADD COLUMN qty_active BIGINT AS (qty_reserved - qty_consumed - qty_released) STORED AFTER qty_released');
        Constraints::check('stock_reservations', 'ck_resa_qty', 'qty_reserved > 0 AND qty_consumed >= 0 AND qty_released >= 0 AND qty_consumed + qty_released <= qty_reserved');
        Constraints::check('stock_reservations', 'ck_resa_status', "status IN ('active','closed')");

        Schema::create('stock_holds', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('lot_id');
            $table->string('reason', 24);
            $table->quantity('qty_held');
            $table->quantity('qty_released')->default(0);
            $table->quantity('qty_consumed')->default(0);
            $table->string('source_type', 32);
            $table->ulidColumn('source_id');
            $table->string('note', 255)->nullable();
            $table->string('status', 16)->default('active');
            $table->auditColumns();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_hold_wh');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_hold_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_hold_lot');
            $table->index(['company_id', 'warehouse_id', 'lot_id', 'status'], 'ix_hold_lot');
        });
        DB::statement('ALTER TABLE stock_holds ADD COLUMN qty_active BIGINT AS (qty_held - qty_released - qty_consumed) STORED AFTER qty_consumed');
        Constraints::check('stock_holds', 'ck_hold_qty', 'qty_held > 0 AND qty_released >= 0 AND qty_consumed >= 0 AND qty_released + qty_consumed <= qty_held');
        Constraints::check('stock_holds', 'ck_hold_reason', "reason IN ('damaged','pending_adjustment','return_inspection','quality','other')");
        Constraints::check('stock_holds', 'ck_hold_status', "status IN ('active','closed')");

        Schema::create('inventories', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('warehouse_id');
            $table->string('scope', 16);
            $table->json('scope_filter')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('snapshot_at', 6)->nullable();
            $table->timestamp('validated_at', 6)->nullable();
            $table->ulidColumn('adjustment_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_inv_wh');
            $table->unique(['company_id', 'number'], 'uq_inv_number');
            $table->index(['company_id', 'warehouse_id', 'status'], 'ix_inv_status');
        });
        Constraints::check('inventories', 'ck_inv_scope', "scope IN ('full','partial')");
        Constraints::check('inventories', 'ck_inv_status', "status IN ('draft','counting','review','validated','cancelled')");

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('warehouse_id');
            $table->ulidColumn('inventory_id')->nullable();
            $table->ulidColumn('transfer_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('reason', 255)->nullable();
            $table->ulidColumn('approval_request_id')->nullable();
            $table->timestamp('posted_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_adj_wh');
            $table->tenantForeign('inventory_id', 'inventories', 'fk_adj_inventory');
            $table->unique(['company_id', 'number'], 'uq_adj_number');
            $table->index(['company_id', 'status'], 'ix_adj_status');
        });
        Constraints::check('stock_adjustments', 'ck_adj_status', "status IN ('draft','pending_approval','posted','rejected','cancelled')");

        Schema::table('inventories', function (Blueprint $table) {
            $table->tenantForeign('adjustment_id', 'stock_adjustments', 'fk_inv_adjustment');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('adjustment_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('lot_id')->nullable();
            $table->quantity('quantity');
            $table->string('kind', 16);
            $table->unitCost('unit_cost')->nullable();
            $table->ulidColumn('hold_id')->nullable();
            $table->ulidColumn('created_lot_id')->nullable();
            $table->auditColumns();

            $table->tenantForeign('adjustment_id', 'stock_adjustments', 'fk_adjl_adjustment');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_adjl_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_adjl_lot');
            $table->tenantForeign('hold_id', 'stock_holds', 'fk_adjl_hold');
            $table->tenantForeign('created_lot_id', 'stock_lots', 'fk_adjl_created_lot');
        });
        Constraints::check('stock_adjustment_lines', 'ck_adjl_qty', 'quantity <> 0');
        Constraints::check('stock_adjustment_lines', 'ck_adjl_kind', "kind IN ('gain','loss','damage','correction')");
        Constraints::check('stock_adjustment_lines', 'ck_adjl_lot', '(quantity > 0) OR (lot_id IS NOT NULL)');

        Schema::create('inventory_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('inventory_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('lot_id')->nullable();
            $table->quantity('qty_expected');
            $table->quantity('qty_counted')->nullable();
            $table->ulidColumn('counted_by')->nullable();
            $table->timestamp('counted_at', 6)->nullable();
            $table->string('note', 255)->nullable();
            $table->auditColumns();

            $table->tenantForeign('inventory_id', 'inventories', 'fk_invl_inventory');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_invl_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_invl_lot');
        });
        DB::statement("ALTER TABLE inventory_lines ADD COLUMN lot_key CHAR(26) CHARACTER SET ascii COLLATE ascii_bin AS (COALESCE(lot_id, '')) STORED");
        DB::statement('ALTER TABLE inventory_lines ADD UNIQUE KEY uq_invline (company_id, inventory_id, variant_id, lot_key)');
        DB::statement('ALTER TABLE inventory_lines ADD COLUMN qty_difference BIGINT AS (qty_counted - qty_expected) STORED');
        Constraints::check('inventory_lines', 'ck_invl_counted', 'qty_counted IS NULL OR qty_counted >= 0');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('from_warehouse_id');
            $table->ulidColumn('to_warehouse_id');
            $table->string('status', 16)->default('draft');
            $table->timestamp('shipped_at', 6)->nullable();
            $table->timestamp('received_at', 6)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('from_warehouse_id', 'warehouses', 'fk_trf_from');
            $table->tenantForeign('to_warehouse_id', 'warehouses', 'fk_trf_to');
            $table->unique(['company_id', 'number'], 'uq_trf_number');
            $table->index(['company_id', 'status'], 'ix_trf_status');
        });
        Constraints::check('stock_transfers', 'ck_trf_distinct', 'from_warehouse_id <> to_warehouse_id');
        Constraints::check('stock_transfers', 'ck_trf_status', "status IN ('draft','confirmed','in_transit','received','cancelled')");

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->tenantForeign('transfer_id', 'stock_transfers', 'fk_adj_transfer');
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('transfer_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('lot_id')->nullable();
            $table->ulidColumn('parent_line_id')->nullable();
            $table->quantity('qty_requested');
            $table->quantity('qty_sent')->default(0);
            $table->quantity('qty_received')->default(0);
            $table->auditColumns();

            $table->tenantForeign('transfer_id', 'stock_transfers', 'fk_trfl_transfer');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_trfl_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_trfl_lot');
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD COLUMN qty_discrepancy BIGINT AS (qty_sent - qty_received) STORED');
        Constraints::check('stock_transfer_lines', 'ck_trfl_qty', 'qty_requested > 0 AND qty_sent >= 0 AND qty_received >= 0 AND qty_received <= qty_sent');
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropForeign('fk_cs_wh'));
        Schema::table('stock_adjustments', fn (Blueprint $table) => $table->dropForeign('fk_adj_transfer'));
        Schema::table('inventories', fn (Blueprint $table) => $table->dropForeign('fk_inv_adjustment'));
        Constraints::dropAppendOnly('stock_movements');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_lots_cost');

        foreach (['stock_transfer_lines', 'stock_transfers', 'inventory_lines', 'stock_adjustment_lines', 'stock_adjustments', 'inventories',
            'stock_holds', 'stock_reservations', 'stock_movements', 'stock_lot_balances', 'stock_positions', 'stock_lots', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
