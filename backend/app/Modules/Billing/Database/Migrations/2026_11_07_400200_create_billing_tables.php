<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Factures, avoirs, imputations (docs/03 §11, L4-04, L4-08) et règlements clients (L4-10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('sales_order_id');
            $table->ulidColumn('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->currencyCode('currency');
            $table->money('subtotal');
            $table->money('discount_total');
            $table->money('tax_total');
            $table->money('total');
            $table->money('amount_paid')->default(0);
            $table->money('amount_credited')->default(0);
            $table->money('balance_due')->storedAs('total - amount_paid - amount_credited');
            $table->string('payment_method', 16)->nullable();
            $table->string('payment_status', 16)->default('unpaid');
            $table->string('status', 16)->default('issued');
            $table->json('customer_snapshot');
            $table->json('company_snapshot');
            $table->auditColumns();

            $table->tenantForeign('sales_order_id', 'sales_orders', 'fk_fac_order');
            $table->tenantForeign('customer_id', 'customers', 'fk_fac_customer');
            $table->foreign('currency', 'fk_fac_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'number'], 'uq_fac_number');
            $table->unique(['company_id', 'sales_order_id'], 'uq_fac_order');
            $table->index(['company_id', 'customer_id', 'issue_date'], 'ix_fac_customer');
            $table->index(['company_id', 'payment_status', 'due_date'], 'ix_fac_due');
        });
        Constraints::check('invoices', 'ck_fac_amounts', 'total >= 0 AND amount_paid >= 0 AND amount_credited >= 0 AND amount_paid + amount_credited <= total');
        Constraints::check('invoices', 'ck_fac_method', "payment_method IS NULL OR payment_method IN ('cash','wave','orange_money','bank_transfer')");
        Constraints::check('invoices', 'ck_fac_pstatus', "payment_status IN ('unpaid','partially_paid','paid')");
        Constraints::check('invoices', 'ck_fac_status', "status IN ('issued','cancelled')");
        // BIL-002 : une facture émise est immuable, hormis les montants réglés et imputés et les statuts.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_invoices_immutable BEFORE UPDATE ON invoices
            FOR EACH ROW
            BEGIN
                IF NEW.number <> OLD.number OR NEW.sales_order_id <> OLD.sales_order_id OR NEW.customer_id <> OLD.customer_id
                    OR NEW.issue_date <> OLD.issue_date OR NEW.due_date <> OLD.due_date OR NEW.currency <> OLD.currency
                    OR NEW.subtotal <> OLD.subtotal OR NEW.discount_total <> OLD.discount_total OR NEW.tax_total <> OLD.tax_total
                    OR NEW.total <> OLD.total OR NOT (NEW.customer_snapshot <=> OLD.customer_snapshot) OR NOT (NEW.company_snapshot <=> OLD.company_snapshot)
                    OR NEW.company_id <> OLD.company_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Facture émise : contenu immuable';
                END IF;
            END
            SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('invoice_id');
            $table->ulidColumn('sales_order_line_id');
            $table->unsignedSmallInteger('position');
            $table->string('description', 255);
            $table->string('unit_label', 32);
            $table->unsignedInteger('qty_units');
            $table->money('unit_price');
            $table->money('discount_amount');
            $table->decimal('tax_rate', 9, 6);
            $table->money('tax_amount');
            $table->money('line_total');
            $table->timestamp('created_at', 6)->useCurrent();

            $table->tenantForeign('invoice_id', 'invoices', 'fk_facl_invoice');
            $table->tenantForeign('sales_order_line_id', 'sales_order_lines', 'fk_facl_sol');
            $table->unique(['company_id', 'invoice_id', 'position'], 'uq_facl');
        });
        Constraints::appendOnly('invoice_lines');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreign(['company_id', 'invoice_id'], 'fk_so_invoice')->references(['company_id', 'id'])->on('invoices');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32)->nullable();
            $table->ulidColumn('customer_id');
            $table->ulidColumn('origin_invoice_id')->nullable();
            $table->ulidColumn('return_id')->nullable();
            $table->string('kind', 16);
            $table->string('reason', 255);
            $table->currencyCode('currency');
            $table->money('total');
            $table->money('amount_applied')->default(0);
            $table->money('amount_available')->storedAs('total - amount_applied');
            $table->string('status', 16)->default('draft');
            $table->timestamp('issued_at', 6)->nullable();
            $table->json('company_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('customer_id', 'customers', 'fk_cn_customer');
            $table->tenantForeign('origin_invoice_id', 'invoices', 'fk_cn_invoice');
            $table->tenantForeign('return_id', 'returns', 'fk_cn_return');
            $table->foreign('currency', 'fk_cn_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'number'], 'uq_cn_number');
            $table->index(['company_id', 'customer_id', 'status'], 'ix_cn_customer');
        });
        Constraints::check('credit_notes', 'ck_cn_amounts', 'total > 0 AND amount_applied >= 0 AND amount_applied <= total');
        Constraints::check('credit_notes', 'ck_cn_kind', "kind IN ('cancellation','return','commercial','price_correction')");
        Constraints::check('credit_notes', 'ck_cn_status', "status IN ('draft','pending_approval','issued','rejected')");
        Constraints::check('credit_notes', 'ck_cn_number', "(status = 'issued') = (number IS NOT NULL)");

        Schema::table('returns', function (Blueprint $table) {
            $table->foreign(['company_id', 'credit_note_id'], 'fk_ret_cn')->references(['company_id', 'id'])->on('credit_notes');
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('credit_note_id');
            $table->ulidColumn('sales_order_line_id')->nullable();
            $table->string('description', 255);
            $table->quantity('quantity')->nullable();
            $table->money('unit_price')->nullable();
            $table->money('tax_amount')->default(0);
            $table->money('line_total');
            $table->auditColumns();

            $table->tenantForeign('credit_note_id', 'credit_notes', 'fk_cnl_cn');
            $table->tenantForeign('sales_order_line_id', 'sales_order_lines', 'fk_cnl_sol');
        });
        Constraints::check('credit_note_lines', 'ck_cnl_total', 'line_total > 0 AND tax_amount >= 0');

        Schema::create('credit_applications', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('credit_note_id');
            $table->ulidColumn('invoice_id');
            $table->money('amount');
            $table->timestamp('applied_at', 6);
            $table->ulidColumn('reverses_application_id')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->ulidColumn('created_by')->nullable();

            $table->tenantForeign('credit_note_id', 'credit_notes', 'fk_ca_cn');
            $table->tenantForeign('invoice_id', 'invoices', 'fk_ca_invoice');
            $table->tenantForeign('reverses_application_id', 'credit_applications', 'fk_ca_reverses');
            $table->index(['company_id', 'invoice_id'], 'ix_ca_invoice');
            $table->index(['company_id', 'credit_note_id'], 'ix_ca_cn');
        });
        Constraints::check('credit_applications', 'ck_ca_amount', 'amount <> 0');
        Constraints::appendOnly('credit_applications');

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('invoice_id');
            $table->ulidColumn('customer_id');
            $table->ulidColumn('financial_account_id');
            $table->string('method', 16);
            $table->money('amount');
            $table->timestamp('paid_at', 6);
            $table->string('external_reference', 64)->nullable();
            $table->string('status', 16)->default('posted');
            $table->ulidColumn('reverses_payment_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->ulidColumn('financial_movement_id')->nullable();
            $table->auditColumns();

            $table->tenantForeign('invoice_id', 'invoices', 'fk_cpay_invoice');
            $table->tenantForeign('customer_id', 'customers', 'fk_cpay_customer');
            $table->tenantForeign('financial_account_id', 'financial_accounts', 'fk_cpay_account');
            $table->tenantForeign('reverses_payment_id', 'customer_payments', 'fk_cpay_reverses');
            $table->tenantForeign('financial_movement_id', 'financial_movements', 'fk_cpay_movement');
            $table->unique(['company_id', 'number'], 'uq_cpay_number');
            $table->unique(['company_id', 'reverses_payment_id'], 'uq_cpay_reversal');
            $table->index(['company_id', 'invoice_id'], 'ix_cpay_invoice');
            $table->index(['company_id', 'paid_at'], 'ix_cpay_date');
        });
        Constraints::check('customer_payments', 'ck_cpay_amount', 'amount <> 0 AND ((amount < 0) = (reverses_payment_id IS NOT NULL))');
        Constraints::check('customer_payments', 'ck_cpay_method', "method IN ('cash','wave','orange_money','bank_transfer')");
        Constraints::check('customer_payments', 'ck_cpay_status', "status IN ('posted','reversed')");
    }

    public function down(): void
    {
        Constraints::dropAppendOnly('credit_applications');
        Constraints::dropAppendOnly('invoice_lines');
        Schema::table('returns', fn (Blueprint $table) => $table->dropForeign('fk_ret_cn'));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropForeign('fk_so_invoice'));
        DB::unprepared('DROP TRIGGER IF EXISTS trg_invoices_immutable');

        foreach (['customer_payments', 'credit_applications', 'credit_note_lines', 'credit_notes', 'invoice_lines', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
