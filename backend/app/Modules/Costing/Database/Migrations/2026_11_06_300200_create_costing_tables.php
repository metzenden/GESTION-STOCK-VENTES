<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Frais d'approche, modèles de calcul, calculs de coût de revient et ajustements de valorisation (docs/03 §9, L3-10 à L3-13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_categories', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 24);
            $table->string('label', 64);
            $table->boolean('is_system')->default(false);
            $table->string('default_allocation', 16)->default('cbm');
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->unique(['company_id', 'code'], 'uq_lcc');
        });
        Constraints::check('landed_cost_categories', 'ck_lcc_alloc', "default_allocation IN ('cbm','direct')");
        Constraints::check('landed_cost_categories', 'ck_lcc_code', "code REGEXP '^[a-z0-9_]{1,24}$'");

        Schema::create('shipment_costs', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('shipment_id');
            $table->ulidColumn('category_id');
            $table->ulidColumn('supplier_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->currencyCode('currency');
            $table->money('amount');
            $table->rate('exchange_rate');
            $table->money('amount_reference');
            $table->string('allocation_method', 16);
            $table->ulidColumn('target_shipment_line_id')->nullable();
            $table->boolean('is_estimate')->default(false);
            $table->ulidColumn('supplier_bill_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_shc_shipment');
            $table->tenantForeign('category_id', 'landed_cost_categories', 'fk_shc_category');
            $table->tenantForeign('supplier_id', 'suppliers', 'fk_shc_supplier');
            $table->tenantForeign('target_shipment_line_id', 'shipment_lines', 'fk_shc_line');
            $table->tenantForeign('supplier_bill_id', 'supplier_bills', 'fk_shc_bill');
            $table->foreign('currency', 'fk_shc_currency')->references('code')->on('currencies');
            $table->index(['company_id', 'shipment_id', 'status'], 'ix_shc');
        });
        Constraints::check('shipment_costs', 'ck_shc_amount', 'amount > 0 AND exchange_rate > 0 AND amount_reference > 0');
        Constraints::check('shipment_costs', 'ck_shc_direct', "(allocation_method = 'direct') = (target_shipment_line_id IS NOT NULL)");
        Constraints::check('shipment_costs', 'ck_shc_method', "allocation_method IN ('cbm','direct')");
        Constraints::check('shipment_costs', 'ck_shc_status', "status IN ('draft','confirmed','cancelled')");

        Schema::create('landed_cost_models', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedInteger('version');
            $table->boolean('is_standard')->default(false);
            $table->json('variables');
            $table->text('expression');
            $table->json('conditions')->nullable();
            $table->date('active_from')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('tested_at', 6)->nullable();
            $table->auditColumns();

            $table->unique(['company_id', 'code', 'version'], 'uq_lcm');
        });
        Constraints::check('landed_cost_models', 'ck_lcm_status', "status IN ('draft','active','retired')");
        // CR-005 : un modèle actif ou retiré n'est jamais modifié ; toute évolution est une nouvelle version.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_lcm_immutable BEFORE UPDATE ON landed_cost_models
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' AND (NEW.expression <> OLD.expression OR NEW.variables <> OLD.variables OR NEW.version <> OLD.version OR NEW.code <> OLD.code) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modèle de coût : une version activée est immuable';
                END IF;
            END
            SQL);

        Schema::create('landed_cost_calculations', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('shipment_id');
            $table->ulidColumn('model_id');
            $table->unsignedSmallInteger('revision');
            $table->string('status', 16)->default('computed');
            $table->currencyCode('reference_currency');
            $table->json('rates_snapshot');
            $table->char('inputs_hash', 64)->charset('ascii');
            $table->decimal('total_cbm', 14, 6);
            $table->money('total_common_fees');
            $table->money('total_direct_fees');
            $table->money('total_purchase');
            $table->money('total_cost');
            $table->json('blocking_issues')->nullable();
            $table->json('discrepancies')->nullable();
            $table->timestamp('computed_at', 6)->nullable();
            $table->timestamp('finalized_at', 6)->nullable();
            $table->ulidColumn('finalized_by')->nullable();
            $table->unsignedTinyInteger('final_flag')->storedAs("IF(status = 'final', 1, NULL)");
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_lcalc_shipment');
            $table->tenantForeign('model_id', 'landed_cost_models', 'fk_lcalc_model');
            $table->foreign('reference_currency', 'fk_lcalc_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'shipment_id', 'revision'], 'uq_lcalc_rev');
            // CR-002 : un seul calcul définitif par arrivage.
            $table->unique(['company_id', 'shipment_id', 'final_flag'], 'uq_lcalc_final');
        });
        Constraints::check('landed_cost_calculations', 'ck_lcalc_cbm', 'total_cbm > 0');
        Constraints::check('landed_cost_calculations', 'ck_lcalc_status', "status IN ('computed','final','superseded')");

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreign(['company_id', 'landed_cost_calculation_id'], 'fk_lot_calc')->references(['company_id', 'id'])->on('landed_cost_calculations');
        });

        Schema::create('landed_cost_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('calculation_id');
            $table->ulidColumn('shipment_line_id');
            $table->ulidColumn('variant_id');
            $table->quantity('qty_accepted');
            $table->unsignedInteger('pieces_per_carton')->nullable();
            $table->decimal('line_cbm', 14, 6);
            $table->currencyCode('purchase_currency');
            $table->money('purchase_amount_foreign');
            $table->rate('exchange_rate');
            $table->unitCost('purchase_amount_converted');
            $table->unitCost('allocated_common_fees');
            $table->unitCost('direct_fees');
            $table->unitCost('rounding_adjustment')->default(0);
            $table->unitCost('total_cost');
            $table->unitCost('unit_cost_piece');
            $table->unitCost('unit_cost_carton')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->tenantForeign('calculation_id', 'landed_cost_calculations', 'fk_lcl_calc');
            $table->tenantForeign('shipment_line_id', 'shipment_lines', 'fk_lcl_line');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_lcl_variant');
            $table->unique(['company_id', 'calculation_id', 'shipment_line_id'], 'uq_lcl');
        });
        Constraints::check('landed_cost_lines', 'ck_lcl_qty', 'qty_accepted > 0');

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('calculation_line_id');
            $table->ulidColumn('shipment_cost_id');
            $table->unitCost('amount');
            $table->timestamp('created_at', 6)->useCurrent();

            $table->tenantForeign('calculation_line_id', 'landed_cost_lines', 'fk_lca_line');
            $table->tenantForeign('shipment_cost_id', 'shipment_costs', 'fk_lca_cost');
            $table->unique(['company_id', 'calculation_line_id', 'shipment_cost_id'], 'uq_lca');
        });

        Schema::create('stock_valuation_adjustments', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('lot_id');
            $table->ulidColumn('calculation_id');
            $table->ulidColumn('stock_movement_id');
            $table->string('movement_type', 24);
            $table->quantity('quantity');
            $table->unitCost('old_unit_cost');
            $table->unitCost('new_unit_cost');
            $table->money('delta_amount');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->ulidColumn('created_by')->nullable();

            $table->tenantForeign('lot_id', 'stock_lots', 'fk_sva_lot');
            $table->tenantForeign('calculation_id', 'landed_cost_calculations', 'fk_sva_calc');
            $table->tenantForeign('stock_movement_id', 'stock_movements', 'fk_sva_mov');
            $table->unique(['company_id', 'stock_movement_id', 'calculation_id'], 'uq_sva');
        });
        Constraints::appendOnly('stock_valuation_adjustments');
        Constraints::appendOnly('landed_cost_lines');
        Constraints::appendOnly('landed_cost_allocations');
    }

    public function down(): void
    {
        Constraints::dropAppendOnly('landed_cost_allocations');
        Constraints::dropAppendOnly('landed_cost_lines');
        Constraints::dropAppendOnly('stock_valuation_adjustments');
        Schema::table('stock_lots', fn (Blueprint $table) => $table->dropForeign('fk_lot_calc'));
        DB::unprepared('DROP TRIGGER IF EXISTS trg_lcm_immutable');

        foreach (['stock_valuation_adjustments', 'landed_cost_allocations', 'landed_cost_lines', 'landed_cost_calculations', 'landed_cost_models', 'shipment_costs', 'landed_cost_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
