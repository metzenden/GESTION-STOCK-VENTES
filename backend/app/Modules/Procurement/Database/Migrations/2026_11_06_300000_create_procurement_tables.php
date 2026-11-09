<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fournisseurs, commandes d'achat et engagements (docs/03 §7, L3-01 à L3-03).
 * Les règlements fournisseurs (supplier_payments) arrivent avec les comptes financiers (L5a-02, D-087).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 32);
            $table->string('name', 200);
            $table->string('supplier_type', 16)->default('goods');
            $table->char('country_code', 2)->nullable();
            $table->currencyCode('currency');
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('has_history')->default(false);
            $table->auditColumns();

            $table->foreign('currency', 'fk_sup_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'code'], 'uq_sup_code');
            $table->index(['company_id', 'name'], 'ix_sup_name');
        });
        Constraints::check('suppliers', 'ck_sup_type', "supplier_type IN ('goods','forwarder','carrier','customs','other')");
        Constraints::check('suppliers', 'ck_sup_status', "status IN ('active','inactive')");

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('supplier_id');
            $table->string('supplier_reference', 64)->nullable();
            $table->date('order_date');
            $table->currencyCode('currency');
            $table->rate('estimated_rate')->nullable();
            $table->string('status', 24)->default('draft');
            $table->money('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('supplier_id', 'suppliers', 'fk_po_supplier');
            $table->foreign('currency', 'fk_po_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'number'], 'uq_po_number');
            $table->index(['company_id', 'supplier_id', 'status'], 'ix_po_supplier');
        });
        Constraints::check('purchase_orders', 'ck_po_status', "status IN ('draft','confirmed','partially_received','received','closed','cancelled')");
        Constraints::check('purchase_orders', 'ck_po_total', 'total_amount >= 0');
        Constraints::check('purchase_orders', 'ck_po_rate', 'estimated_rate IS NULL OR estimated_rate > 0');

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('purchase_order_id');
            $table->unsignedSmallInteger('position');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('unit_id');
            $table->unsignedInteger('unit_factor');
            $table->unsignedInteger('qty_units');
            $table->quantity('qty_base');
            $table->unitCost('unit_price');
            $table->money('line_total');
            $table->quantity('qty_received')->default(0);
            $table->auditColumns();

            $table->tenantForeign('purchase_order_id', 'purchase_orders', 'fk_pol_po');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_pol_variant');
            $table->tenantForeign('unit_id', 'variant_units', 'fk_pol_unit');
            $table->unique(['company_id', 'purchase_order_id', 'position'], 'uq_pol_position');
            $table->index(['company_id', 'variant_id'], 'ix_pol_variant');
        });
        Constraints::check('purchase_order_lines', 'ck_pol_qty', 'qty_units > 0 AND unit_factor >= 1 AND qty_base = qty_units * unit_factor');
        Constraints::check('purchase_order_lines', 'ck_pol_received', 'qty_received >= 0');
        Constraints::check('purchase_order_lines', 'ck_pol_price', 'unit_price >= 0 AND line_total >= 0');

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('supplier_id');
            $table->string('supplier_invoice_ref', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('source_type', 32);
            $table->ulidColumn('source_id')->nullable();
            $table->ulidColumn('compensates_bill_id')->nullable();
            $table->currencyCode('currency');
            $table->money('amount');
            $table->money('amount_paid')->default(0);
            $table->money('balance')->storedAs('amount - amount_paid');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('open');
            $table->auditColumns();

            $table->tenantForeign('supplier_id', 'suppliers', 'fk_bill_supplier');
            $table->tenantForeign('compensates_bill_id', 'supplier_bills', 'fk_bill_compensates');
            $table->foreign('currency', 'fk_bill_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'number'], 'uq_bill_number');
            $table->index(['company_id', 'supplier_id', 'status'], 'ix_bill_supplier');
            $table->index(['company_id', 'source_type', 'source_id'], 'ix_bill_source');
            // Une compensation par engagement : l'annulation d'un BC ne peut pas compenser deux fois (PUR-004).
            $table->unique(['company_id', 'compensates_bill_id'], 'uq_bill_compensation');
        });
        Constraints::check('supplier_bills', 'ck_bill_amount', 'amount <> 0');
        Constraints::check('supplier_bills', 'ck_bill_source', "source_type IN ('purchase_order','shipment_cost','manual','supplier_credit')");
        Constraints::check('supplier_bills', 'ck_bill_status', "status IN ('open','partially_paid','paid','cancelled')");
        Constraints::check('supplier_bills', 'ck_bill_paid', 'amount_paid >= 0 AND amount_paid <= ABS(amount)');
    }

    public function down(): void
    {
        foreach (['supplier_bills', 'purchase_order_lines', 'purchase_orders', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
