<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot 5 (docs/03 §12) : transferts entre comptes, dépenses, corrections financières, document d'ouverture. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('from_account_id');
            $table->ulidColumn('to_account_id');
            $table->money('amount_from');
            $table->money('amount_to');
            $table->rate('exchange_rate')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('posted');
            $table->auditColumns();

            $table->tenantForeign('from_account_id', 'financial_accounts', 'fk_atr_from');
            $table->tenantForeign('to_account_id', 'financial_accounts', 'fk_atr_to');
            $table->unique(['company_id', 'number'], 'uq_atr_number');
        });
        Constraints::check('account_transfers', 'ck_atr_values', 'from_account_id <> to_account_id AND amount_from > 0 AND amount_to > 0');

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('name', 96);
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->unique(['company_id', 'name'], 'uq_expcat');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->ulidColumn('category_id');
            $table->ulidColumn('financial_account_id');
            $table->date('expense_date');
            $table->string('label', 255);
            $table->money('amount');
            $table->currencyCode('currency');
            $table->ulidColumn('attachment_file_id')->nullable();
            $table->string('status', 16)->default('posted');
            $table->ulidColumn('financial_movement_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->auditColumns();

            $table->tenantForeign('category_id', 'expense_categories', 'fk_exp_category');
            $table->tenantForeign('financial_account_id', 'financial_accounts', 'fk_exp_account');
            $table->tenantForeign('attachment_file_id', 'files', 'fk_exp_file');
            $table->tenantForeign('financial_movement_id', 'financial_movements', 'fk_exp_movement');
            $table->unique(['company_id', 'number'], 'uq_exp_number');
            $table->index(['company_id', 'category_id', 'expense_date'], 'ix_exp_cat_date');
        });
        Constraints::check('expenses', 'ck_exp_amount', 'amount > 0');
        Constraints::check('expenses', 'ck_exp_status', "status IN ('posted','reversed')");

        Schema::create('financial_corrections', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('financial_account_id');
            $table->char('direction', 3);
            $table->money('amount');
            $table->string('reason', 255);
            $table->string('status', 16)->default('pending_approval');
            $table->ulidColumn('financial_movement_id')->nullable();
            $table->timestamp('posted_at', 6)->nullable();
            $table->auditColumns();

            $table->tenantForeign('financial_account_id', 'financial_accounts', 'fk_fcor_account');
            $table->tenantForeign('financial_movement_id', 'financial_movements', 'fk_fcor_movement');
        });
        Constraints::check('financial_corrections', 'ck_fcor_values', "amount > 0 AND direction IN ('in','out')");
        Constraints::check('financial_corrections', 'ck_fcor_status', "status IN ('pending_approval','posted','rejected','cancelled')");

        Schema::create('company_openings', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->date('start_date');
            $table->string('status', 16)->default('draft');
            $table->json('accounts');
            $table->json('stock');
            $table->timestamp('posted_at', 6)->nullable();
            $table->ulidColumn('posted_by')->nullable();
            $table->json('summary')->nullable();
            $table->auditColumns();

            // FIN-007 : une seule ouverture par entreprise.
            $table->unique(['company_id'], 'uq_opening');
        });
        Constraints::check('company_openings', 'ck_opening_status', "status IN ('draft','posted')");
    }

    public function down(): void
    {
        foreach (['company_openings', 'financial_corrections', 'expenses', 'expense_categories', 'account_transfers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
