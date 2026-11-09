<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trésorerie de base (docs/03 §12, L5a-01, L5a-02) : comptes et mouvements financiers en ajout seul. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 16);
            $table->string('name', 120);
            $table->string('account_type', 16);
            $table->currencyCode('currency');
            $table->boolean('allow_negative')->default(false);
            $table->money('balance')->default(0);
            $table->string('status', 16)->default('active');
            $table->auditColumns();

            $table->foreign('currency', 'fk_fa_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'code'], 'uq_fa_code');
        });
        Constraints::check('financial_accounts', 'ck_fa_type', "account_type IN ('cash','wave','orange_money','bank','other')");
        Constraints::check('financial_accounts', 'ck_fa_status', "status IN ('active','inactive')");
        // FIN-003 : garde-fou en base, en plus du contrôle sous verrou.
        Constraints::check('financial_accounts', 'ck_fa_negative', 'allow_negative OR balance >= 0');

        Schema::create('financial_movements', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('financial_account_id');
            $table->string('movement_type', 24);
            $table->char('direction', 3);
            $table->money('amount');
            $table->money('signed_amount')->storedAs("IF(direction = 'in', amount, -amount)");
            $table->currencyCode('currency');
            $table->timestamp('occurred_at', 6);
            $table->string('description', 255);
            $table->string('source_type', 32);
            $table->ulidColumn('source_id');
            $table->unsignedSmallInteger('reversal_seq')->default(0);
            $table->ulidColumn('reverses_movement_id')->nullable();
            $table->money('balance_after');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->ulidColumn('created_by');

            $table->tenantForeign('financial_account_id', 'financial_accounts', 'fk_fm_account');
            $table->tenantForeign('reverses_movement_id', 'financial_movements', 'fk_fm_reverses');
            $table->foreign('currency', 'fk_fm_currency')->references('code')->on('currencies');
            $table->unique(['company_id', 'source_type', 'source_id', 'financial_account_id', 'movement_type', 'reversal_seq'], 'uq_fm_source');
            $table->unique(['company_id', 'reverses_movement_id'], 'uq_fm_reversal');
            $table->index(['company_id', 'financial_account_id', 'occurred_at'], 'ix_fm_account_time');
            $table->index(['company_id', 'movement_type', 'occurred_at'], 'ix_fm_type_time');
        });
        Constraints::check('financial_movements', 'ck_fm_amount', 'amount > 0');
        Constraints::check('financial_movements', 'ck_fm_direction', "direction IN ('in','out')");
        Constraints::check('financial_movements', 'ck_fm_type', "movement_type IN ('customer_receipt','supplier_payment','expense','transfer_in','transfer_out','opening','correction','landed_cost_payment','other_in','other_out','reversal')");
        Constraints::appendOnly('financial_movements');
    }

    public function down(): void
    {
        Constraints::dropAppendOnly('financial_movements');
        Schema::dropIfExists('financial_movements');
        Schema::dropIfExists('financial_accounts');
    }
};
