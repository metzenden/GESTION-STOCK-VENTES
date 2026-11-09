<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrivages, conteneurs, articles attendus et réceptions (docs/03 §8, L3-04, L3-08, L3-09).
 * Les packing lists relèvent du module Imports (leur FK vers shipment_lines y est ajoutée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->string('reference', 64)->nullable();
            $table->string('carrier', 120)->nullable();
            $table->char('origin_country', 2)->nullable();
            $table->date('shipped_at')->nullable();
            $table->date('eta')->nullable();
            $table->date('arrived_at')->nullable();
            $table->string('status', 24)->default('preparing');
            $table->ulidColumn('default_warehouse_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('default_warehouse_id', 'warehouses', 'fk_shp_wh');
            $table->unique(['company_id', 'number'], 'uq_shp_number');
            $table->index(['company_id', 'status', 'eta'], 'ix_shp_status_eta');
        });
        Constraints::check('shipments', 'ck_shp_status', "status IN ('preparing','shipped','in_transit','arrived','unloading','partially_received','received','closed','cancelled')");

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreign(['company_id', 'shipment_id'], 'fk_lot_shipment')->references(['company_id', 'id'])->on('shipments');
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('shipment_id');
            $table->string('container_number', 20);
            $table->string('container_type', 16)->nullable();
            $table->string('seal_number', 32)->nullable();
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_cont_shipment');
            $table->unique(['company_id', 'shipment_id', 'container_number'], 'uq_cont');
        });

        Schema::create('shipment_purchase_orders', function (Blueprint $table) {
            $table->ulidColumn('company_id');
            $table->ulidColumn('shipment_id');
            $table->ulidColumn('purchase_order_id');
            $table->timestamp('created_at', 6)->useCurrent();

            $table->primary(['company_id', 'shipment_id', 'purchase_order_id']);
            $table->tenantForeign('shipment_id', 'shipments', 'fk_spo_shipment');
            $table->tenantForeign('purchase_order_id', 'purchase_orders', 'fk_spo_po');
        });

        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('shipment_id');
            $table->unsignedInteger('position');
            $table->ulidColumn('container_id')->nullable();
            $table->ulidColumn('purchase_order_line_id')->nullable();
            $table->ulidColumn('supplier_id')->nullable();
            $table->ulidColumn('variant_id');
            $table->ulidColumn('destination_warehouse_id');
            $table->unsignedInteger('cartons')->nullable();
            $table->unsignedInteger('pieces_per_carton')->nullable();
            $table->quantity('qty_expected');
            $table->quantity('qty_received')->default(0);
            $table->decimal('cbm_total', 14, 6)->nullable();
            $table->currencyCode('purchase_currency');
            $table->unitCost('purchase_unit_price');
            $table->money('purchase_amount');
            $table->ulidColumn('packing_list_line_id')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_shl_shipment');
            $table->tenantForeign('container_id', 'containers', 'fk_shl_container');
            $table->tenantForeign('purchase_order_line_id', 'purchase_order_lines', 'fk_shl_pol');
            $table->tenantForeign('supplier_id', 'suppliers', 'fk_shl_supplier');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_shl_variant');
            $table->tenantForeign('destination_warehouse_id', 'warehouses', 'fk_shl_wh');
            $table->foreign('purchase_currency', 'fk_shl_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'shipment_id', 'position'], 'uq_shl_position');
            $table->index(['company_id', 'destination_warehouse_id', 'variant_id'], 'ix_shl_expected');
        });
        Constraints::check('shipment_lines', 'ck_shl_qty', 'qty_expected > 0 AND qty_received >= 0');
        Constraints::check('shipment_lines', 'ck_shl_cbm', 'cbm_total IS NULL OR cbm_total > 0');
        Constraints::check('shipment_lines', 'ck_shl_price', 'purchase_unit_price >= 0 AND purchase_amount >= 0');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('shipment_id')->nullable();
            $table->ulidColumn('purchase_order_id')->nullable();
            $table->ulidColumn('warehouse_id');
            $table->string('status', 16)->default('draft');
            $table->string('counting_mode', 16)->default('manual');
            $table->text('note')->nullable();
            $table->timestamp('validated_at', 6)->nullable();
            $table->ulidColumn('validated_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_gr_shipment');
            $table->tenantForeign('purchase_order_id', 'purchase_orders', 'fk_gr_po');
            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_gr_wh');
            $table->unique(['company_id', 'number'], 'uq_gr_number');
            $table->index(['company_id', 'shipment_id'], 'ix_gr_shipment');
        });
        Constraints::check('goods_receipts', 'ck_gr_origin', '(shipment_id IS NULL) <> (purchase_order_id IS NULL)');
        Constraints::check('goods_receipts', 'ck_gr_status', "status IN ('draft','counting','validated','cancelled')");
        Constraints::check('goods_receipts', 'ck_gr_mode', "counting_mode IN ('manual','scan')");

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('goods_receipt_id');
            $table->ulidColumn('shipment_line_id')->nullable();
            $table->ulidColumn('purchase_order_line_id')->nullable();
            $table->ulidColumn('variant_id');
            $table->quantity('qty_expected')->default(0);
            $table->quantity('qty_accepted')->default(0);
            $table->quantity('qty_damaged')->default(0);
            $table->quantity('qty_counted')->storedAs('qty_accepted + qty_damaged');
            $table->quantity('qty_missing')->storedAs('GREATEST(qty_expected - (qty_accepted + qty_damaged), 0)');
            $table->quantity('qty_surplus')->storedAs('GREATEST((qty_accepted + qty_damaged) - qty_expected, 0)');
            $table->string('damaged_disposition', 16)->default('hold');
            $table->boolean('is_counted')->default(false);
            $table->ulidColumn('lot_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->auditColumns();

            $table->tenantForeign('goods_receipt_id', 'goods_receipts', 'fk_grl_gr');
            $table->tenantForeign('shipment_line_id', 'shipment_lines', 'fk_grl_shl');
            $table->tenantForeign('purchase_order_line_id', 'purchase_order_lines', 'fk_grl_pol');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_grl_variant');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_grl_lot');
            // IMP-004 : un lot ne naît que d'une ligne de réception.
            $table->unique(['company_id', 'lot_id'], 'uq_grl_lot');
            $table->unique(['company_id', 'goods_receipt_id', 'shipment_line_id'], 'uq_grl_shl');
            $table->unique(['company_id', 'goods_receipt_id', 'purchase_order_line_id'], 'uq_grl_pol');
        });
        Constraints::check('goods_receipt_lines', 'ck_grl_qty', 'qty_accepted >= 0 AND qty_damaged >= 0 AND qty_expected >= 0');
        Constraints::check('goods_receipt_lines', 'ck_grl_disposition', "damaged_disposition IN ('hold','reject')");

        Schema::create('receipt_scan_events', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('goods_receipt_id');
            $table->ulidColumn('goods_receipt_line_id')->nullable();
            $table->string('scanned_code', 64);
            $table->ulidColumn('resolved_unit_id')->nullable();
            $table->quantity('qty_base');
            $table->boolean('damaged')->default(false);
            $table->string('outcome', 16);
            $table->ulidColumn('scanned_by');
            $table->timestamp('scanned_at', 6);
            $table->string('client_event_id', 64);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->tenantForeign('goods_receipt_id', 'goods_receipts', 'fk_scan_gr');
            $table->tenantForeign('goods_receipt_line_id', 'goods_receipt_lines', 'fk_scan_grl');
            // IMP-010 : un envoi répété par le terminal n'est compté qu'une fois.
            $table->unique(['company_id', 'goods_receipt_id', 'client_event_id'], 'uq_scan');
        });
        Constraints::check('receipt_scan_events', 'ck_scan_outcome', "outcome IN ('counted','unknown','not_expected')");
    }

    public function down(): void
    {
        Schema::table('stock_lots', fn (Blueprint $table) => $table->dropForeign('fk_lot_shipment'));

        foreach (['receipt_scan_events', 'goods_receipt_lines', 'goods_receipts', 'shipment_lines', 'shipment_purchase_orders', 'containers', 'shipments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
