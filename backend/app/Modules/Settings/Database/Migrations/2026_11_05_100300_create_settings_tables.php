<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paramètres d'entreprise, taux de change datés et taux de taxe (docs/03 §4, L1-14). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->ulidColumn('company_id')->primary();
            // FK vers warehouses ajoutée au lot 2.
            $table->ulidColumn('default_sales_warehouse_id')->nullable();
            $table->unsignedSmallInteger('default_payment_terms_days')->default(0);
            $table->string('rounding_mode', 16)->default('half_up');
            $table->unsignedInteger('low_stock_default_threshold')->default(0);
            $table->text('invoice_legal_mentions')->nullable();
            $table->text('invoice_footer')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->date('start_date')->nullable();
            $table->boolean('allow_self_approval')->default(true);
            $table->json('extra')->nullable();
            $table->timestamp('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->ulidColumn('updated_by')->nullable();

            $table->foreign('company_id', 'fk_company_settings_company')->references('id')->on('companies');
        });
        Constraints::check('company_settings', 'ck_company_settings_rounding', "rounding_mode IN ('half_up')");

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->currencyCode('from_currency');
            $table->currencyCode('to_currency');
            $table->rate('rate');
            $table->date('valid_on');
            $table->string('source', 64)->nullable();
            $table->auditColumns();

            $table->foreign('from_currency', 'fk_rates_from')->references('code')->on('currencies');
            $table->foreign('to_currency', 'fk_rates_to')->references('code')->on('currencies');
            $table->unique(['company_id', 'from_currency', 'to_currency', 'valid_on'], 'uq_rate');
        });
        Constraints::check('exchange_rates', 'ck_rates_positive', 'rate > 0');
        Constraints::check('exchange_rates', 'ck_rates_distinct', 'from_currency <> to_currency');

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('name', 64);
            $table->decimal('rate', 9, 6);
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->unique(['company_id', 'name'], 'uq_tax_name');
        });
        Constraints::check('tax_rates', 'ck_tax_rate_range', 'rate >= 0 AND rate <= 100');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('company_settings');
    }
};
