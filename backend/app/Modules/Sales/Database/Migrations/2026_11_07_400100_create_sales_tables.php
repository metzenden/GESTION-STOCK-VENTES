<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Clients, devis, commandes, livraisons, retours, litiges (docs/03 §10, L4-01 à L4-14). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 32);
            $table->string('name', 200);
            $table->string('customer_type', 16)->default('individual');
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 255)->nullable();
            $table->ulidColumn('default_price_list_id')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('is_walk_in')->default(false);
            $table->string('status', 16)->default('active');
            $table->boolean('has_history')->default(false);
            $table->auditColumns();

            $table->tenantForeign('default_price_list_id', 'price_lists', 'fk_cust_pricelist');
            $table->unique(['company_id', 'code'], 'uq_cust_code');
            $table->index(['company_id', 'phone'], 'ix_cust_phone');
            $table->index(['company_id', 'name'], 'ix_cust_name');
        });
        Constraints::check('customers', 'ck_cust_type', "customer_type IN ('individual','business')");
        Constraints::check('customers', 'ck_cust_status', "status IN ('active','inactive')");

        foreach (['quotation', 'sales_order'] as $doc) {
            $parent = $doc === 'quotation' ? 'quotations' : 'sales_orders';
            $prefix = $doc === 'quotation' ? 'quo' : 'so';

            Schema::create($parent, function (Blueprint $table) use ($doc, $prefix) {
                $table->ulidPrimary();
                $table->tenant();
                $table->string('number', 32);
                $table->ulidColumn('customer_id');
                $table->ulidColumn('price_list_id');
                $table->ulidColumn('warehouse_id')->nullable($doc === 'quotation');
                $table->currencyCode('currency');
                $table->string('global_discount_type', 8)->nullable();
                $table->decimal('global_discount_value', 19, 6)->nullable();
                $table->money('subtotal')->default(0);
                $table->money('discount_total')->default(0);
                $table->money('tax_total')->default(0);
                $table->money('total')->default(0);
                $table->string('status', 24)->default('draft');
                $table->text('notes')->nullable();

                if ($doc === 'quotation') {
                    $table->date('issue_date');
                    $table->date('valid_until')->nullable();
                    $table->ulidColumn('converted_order_id')->nullable();
                    $table->unique(['company_id', 'converted_order_id'], 'uq_quo_conv');
                    $table->index(['company_id', 'customer_id', 'issue_date'], 'ix_quo_customer');
                } else {
                    $table->ulidColumn('quotation_id')->nullable();
                    $table->string('channel', 16)->default('standard');
                    $table->string('fulfilment_mode', 16)->default('pickup');
                    $table->string('recipient_name', 160)->nullable();
                    $table->string('recipient_phone', 32)->nullable();
                    $table->string('delivery_address', 255)->nullable();
                    $table->boolean('has_dispute')->default(false);
                    $table->ulidColumn('invoice_id')->nullable();
                    $table->timestamp('confirmed_at', 6)->nullable();
                    $table->ulidColumn('confirmed_by')->nullable();
                    $table->timestamp('cancelled_at', 6)->nullable();
                    $table->string('cancellation_reason', 255)->nullable();
                    $table->ulidColumn('parent_order_id')->nullable();
                    $table->unique(['company_id', 'invoice_id'], 'uq_so_invoice');
                    $table->index(['company_id', 'customer_id', 'created_at'], 'ix_so_customer');
                    $table->index(['company_id', 'status', 'created_at'], 'ix_so_status');
                }

                $table->unsignedInteger('version')->default(1);
                $table->auditColumns();

                $table->tenantForeign('customer_id', 'customers', "fk_{$prefix}_customer");
                $table->tenantForeign('price_list_id', 'price_lists', "fk_{$prefix}_pricelist");
                $table->tenantForeign('warehouse_id', 'warehouses', "fk_{$prefix}_wh");
                $table->foreign('currency', "fk_{$prefix}_currency")->references('code')->on('currencies');
                $table->unique(['company_id', 'number'], "uq_{$prefix}_number");
            });
            Constraints::check($parent, "ck_{$prefix}_gdisc", "global_discount_type IS NULL OR global_discount_type IN ('percent','amount')");
            Constraints::check($parent, "ck_{$prefix}_totals", 'subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND total >= 0');

            $lines = $doc === 'quotation' ? 'quotation_lines' : 'sales_order_lines';
            $lp = $doc === 'quotation' ? 'quol' : 'sol';

            Schema::create($lines, function (Blueprint $table) use ($doc, $parent, $lp) {
                $table->ulidPrimary();
                $table->tenant();
                $table->ulidColumn($doc === 'quotation' ? 'quotation_id' : 'sales_order_id');
                $table->unsignedSmallInteger('position');
                $table->ulidColumn('variant_id');
                $table->string('product_type', 16);
                $table->ulidColumn('unit_id');
                $table->unsignedInteger('unit_factor');
                $table->string('unit_label', 32);
                $table->string('description', 255);
                $table->unsignedInteger('qty_units');
                $table->quantity('qty_base');
                $table->money('list_unit_price');
                $table->money('unit_price');
                $table->boolean('price_overridden')->storedAs('unit_price <> list_unit_price');
                $table->string('discount_type', 8)->nullable();
                $table->decimal('discount_value', 19, 6)->nullable();
                $table->money('discount_amount')->default(0);
                $table->ulidColumn('tax_rate_id')->nullable();
                $table->decimal('tax_rate', 9, 6)->default(0);
                $table->money('tax_amount')->default(0);
                $table->money('line_total');

                if ($doc === 'sales_order') {
                    $table->quantity('qty_delivered')->default(0);
                    $table->quantity('qty_returned')->default(0);
                    $table->quantity('qty_cancelled')->default(0);
                    $table->unitCost('estimated_unit_cost')->nullable();
                }

                $table->auditColumns();

                $table->tenantForeign($doc === 'quotation' ? 'quotation_id' : 'sales_order_id', $parent, "fk_{$lp}_parent");
                $table->tenantForeign('variant_id', 'product_variants', "fk_{$lp}_variant");
                $table->tenantForeign('unit_id', 'variant_units', "fk_{$lp}_unit");
                $table->tenantForeign('tax_rate_id', 'tax_rates', "fk_{$lp}_tax");
                $table->unique(['company_id', $doc === 'quotation' ? 'quotation_id' : 'sales_order_id', 'position'], "uq_{$lp}_pos");
                $table->index(['company_id', 'variant_id'], "ix_{$lp}_variant");
            });
            Constraints::check($lines, "ck_{$lp}_qty", 'qty_units > 0 AND unit_factor >= 1 AND qty_base = qty_units * unit_factor');
            Constraints::check($lines, "ck_{$lp}_amounts", 'unit_price >= 0 AND list_unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0');
            Constraints::check($lines, "ck_{$lp}_dtype", "discount_type IS NULL OR discount_type IN ('percent','amount')");
        }

        Constraints::check('quotations', 'ck_quo_status', "status IN ('draft','sent','accepted','converted','expired','cancelled')");
        Constraints::check('sales_orders', 'ck_so_status', "status IN ('draft','pending_approval','confirmed','preparing','ready','in_delivery','partially_delivered','delivered','cancelled')");
        Constraints::check('sales_orders', 'ck_so_mode', "fulfilment_mode IN ('pickup','delivery')");
        Constraints::check('sales_orders', 'ck_so_channel', "channel IN ('standard','counter')");
        Constraints::check('sales_order_lines', 'ck_sol_progress', 'qty_delivered >= 0 AND qty_cancelled >= 0 AND qty_delivered + qty_cancelled <= qty_base AND qty_returned >= 0 AND qty_returned <= qty_delivered');

        Schema::table('quotations', function (Blueprint $table) {
            $table->tenantForeign('converted_order_id', 'sales_orders', 'fk_quo_order');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->tenantForeign('quotation_id', 'quotations', 'fk_so_quotation');
            $table->tenantForeign('parent_order_id', 'sales_orders', 'fk_so_parent');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('sales_order_id');
            $table->ulidColumn('warehouse_id');
            $table->string('mode', 16);
            $table->string('status', 16)->default('preparing');
            $table->string('recipient_name', 160)->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('courier', 120)->nullable();
            $table->timestamp('prepared_at', 6)->nullable();
            $table->timestamp('shipped_at', 6)->nullable();
            $table->timestamp('delivered_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('sales_order_id', 'sales_orders', 'fk_dlv_order');
            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_dlv_wh');
            $table->unique(['company_id', 'number'], 'uq_dlv_number');
            $table->index(['company_id', 'sales_order_id'], 'ix_dlv_order');
            $table->index(['company_id', 'status', 'created_at'], 'ix_dlv_status');
        });
        Constraints::check('deliveries', 'ck_dlv_status', "status IN ('preparing','ready','in_delivery','delivered','failed','cancelled')");
        Constraints::check('deliveries', 'ck_dlv_mode', "mode IN ('pickup','delivery')");

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('delivery_id');
            $table->ulidColumn('sales_order_line_id');
            $table->ulidColumn('variant_id');
            $table->quantity('quantity');
            $table->quantity('qty_delivered')->default(0);
            $table->auditColumns();

            $table->tenantForeign('delivery_id', 'deliveries', 'fk_dl_delivery');
            $table->tenantForeign('sales_order_line_id', 'sales_order_lines', 'fk_dl_sol');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_dl_variant');
            $table->unique(['company_id', 'delivery_id', 'sales_order_line_id'], 'uq_dl');
        });
        Constraints::check('delivery_lines', 'ck_dl_qty', 'quantity > 0 AND qty_delivered >= 0 AND qty_delivered <= quantity');

        Schema::create('delivery_line_lots', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('delivery_line_id');
            $table->ulidColumn('lot_id');
            $table->quantity('quantity');
            $table->string('allocation_method', 8);
            $table->unitCost('unit_cost');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 16)->default('allocated');
            $table->auditColumns();

            $table->tenantForeign('delivery_line_id', 'delivery_lines', 'fk_dll_line');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_dll_lot');
            $table->unique(['company_id', 'delivery_line_id', 'lot_id', 'attempt'], 'uq_dll');
        });
        Constraints::check('delivery_line_lots', 'ck_dll_qty', 'quantity > 0');
        Constraints::check('delivery_line_lots', 'ck_dll_method', "allocation_method IN ('fifo','manual')");
        Constraints::check('delivery_line_lots', 'ck_dll_status', "status IN ('allocated','delivered','returned')");

        Schema::create('returns', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('sales_order_id');
            $table->ulidColumn('customer_id');
            $table->ulidColumn('warehouse_id');
            $table->string('return_type', 16);
            $table->string('status', 16)->default('draft');
            $table->ulidColumn('credit_note_id')->nullable();
            $table->ulidColumn('exchange_order_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('received_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('sales_order_id', 'sales_orders', 'fk_ret_order');
            $table->tenantForeign('customer_id', 'customers', 'fk_ret_customer');
            $table->tenantForeign('warehouse_id', 'warehouses', 'fk_ret_wh');
            $table->tenantForeign('exchange_order_id', 'sales_orders', 'fk_ret_exchange');
            $table->unique(['company_id', 'number'], 'uq_ret_number');
        });
        Constraints::check('returns', 'ck_ret_type', "return_type IN ('refused','defective','exchange','other')");
        Constraints::check('returns', 'ck_ret_status', "status IN ('draft','received','inspected','closed','cancelled')");

        Schema::create('return_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('return_id');
            $table->ulidColumn('sales_order_line_id');
            $table->ulidColumn('delivery_line_lot_id');
            $table->ulidColumn('lot_id');
            $table->ulidColumn('variant_id');
            $table->quantity('quantity');
            $table->string('disposition', 16)->nullable();
            $table->unitCost('unit_cost');
            $table->money('credit_amount')->default(0);
            $table->ulidColumn('hold_id')->nullable();
            $table->auditColumns();

            $table->tenantForeign('return_id', 'returns', 'fk_retl_return');
            $table->tenantForeign('sales_order_line_id', 'sales_order_lines', 'fk_retl_sol');
            $table->tenantForeign('delivery_line_lot_id', 'delivery_line_lots', 'fk_retl_dll');
            $table->tenantForeign('lot_id', 'stock_lots', 'fk_retl_lot');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_retl_variant');
        });
        Constraints::check('return_lines', 'ck_retl_qty', 'quantity > 0');
        Constraints::check('return_lines', 'ck_retl_disp', "disposition IS NULL OR disposition IN ('restock','damaged')");

        Schema::create('disputes', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('sales_order_id');
            $table->string('status', 16)->default('open');
            $table->string('reason', 500);
            $table->string('resolution', 500)->nullable();
            $table->timestamp('opened_at', 6);
            $table->timestamp('resolved_at', 6)->nullable();
            $table->auditColumns();

            $table->tenantForeign('sales_order_id', 'sales_orders', 'fk_disp_order');
        });
        Constraints::check('disputes', 'ck_disp_status', "status IN ('open','resolved')");
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign('fk_so_quotation');
            $table->dropForeign('fk_so_parent');
        });
        Schema::table('quotations', fn (Blueprint $table) => $table->dropForeign('fk_quo_order'));

        foreach (['disputes', 'return_lines', 'returns', 'delivery_line_lots', 'delivery_lines', 'deliveries', 'sales_order_lines', 'quotation_lines', 'sales_orders', 'quotations', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
