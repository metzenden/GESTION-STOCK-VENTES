<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Catalogue (docs/03 §5, L2-01 à L2-05). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('path', 1000)->charset('ascii')->collation('ascii_bin');
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->tenantForeign('parent_id', 'categories', 'fk_categories_parent');
            $table->index(['company_id', 'path'], 'ix_cat_path');
        });
        // Unicité du nom par parent, racine comprise (NULL ne serait pas comparé par un index unique).
        DB::statement("ALTER TABLE categories ADD COLUMN parent_key CHAR(26) CHARACTER SET ascii COLLATE ascii_bin AS (COALESCE(parent_id, '')) STORED");
        DB::statement('ALTER TABLE categories ADD UNIQUE KEY uq_cat_name (company_id, parent_key, name)');

        Schema::create('products', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('reference', 64);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->ulidColumn('category_id')->nullable();
            $table->string('product_type', 16)->default('stock');
            $table->string('base_unit_label', 16)->default('pièce');
            $table->boolean('has_variants')->default(false);
            $table->string('status', 16)->default('active');
            $table->boolean('has_history')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->unique(['company_id', 'reference'], 'uq_products_ref');
            $table->tenantForeign('category_id', 'categories', 'fk_products_category');
            $table->index(['company_id', 'status', 'name'], 'ix_products_status_name');
        });
        Constraints::check('products', 'ck_products_type', "product_type IN ('stock','service')");
        Constraints::check('products', 'ck_products_status', "status IN ('active','inactive','archived')");
        DB::statement('ALTER TABLE products ADD FULLTEXT KEY ft_products (reference, name, description) WITH PARSER ngram');

        Schema::create('attributes', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('name', 64);
            $table->auditColumns();

            $table->unique(['company_id', 'name'], 'uq_attr');
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('attribute_id');
            $table->string('value', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->auditColumns();

            $table->tenantForeign('attribute_id', 'attributes', 'fk_attrval_attribute');
            $table->unique(['company_id', 'attribute_id', 'value'], 'uq_attr_val');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('product_id');
            $table->string('sku', 64);
            $table->string('label', 160);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('pieces_per_carton')->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->string('status', 16)->default('active');
            $table->auditColumns();

            $table->tenantForeign('product_id', 'products', 'fk_variants_product');
            $table->unique(['company_id', 'sku'], 'uq_variants_sku');
            $table->index(['company_id', 'product_id'], 'ix_variants_product');
        });
        Constraints::check('product_variants', 'ck_variants_ppc', 'pieces_per_carton IS NULL OR pieces_per_carton > 0');
        Constraints::check('product_variants', 'ck_variants_status', "status IN ('active','inactive')");

        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->ulidColumn('company_id');
            $table->ulidColumn('variant_id');
            $table->ulidColumn('attribute_value_id');

            $table->primary(['company_id', 'variant_id', 'attribute_value_id']);
            $table->tenantForeign('variant_id', 'product_variants', 'fk_vav_variant');
            $table->tenantForeign('attribute_value_id', 'attribute_values', 'fk_vav_value');
        });

        Schema::create('variant_units', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('variant_id');
            $table->string('code', 16);
            $table->string('label', 32);
            $table->unsignedInteger('factor');
            $table->string('barcode', 64)->nullable();
            $table->boolean('is_sellable')->default(true);
            // Posé au premier usage dans un document : le facteur devient immuable (CAT-007).
            $table->timestamp('locked_at', 6)->nullable();
            $table->auditColumns();

            $table->tenantForeign('variant_id', 'product_variants', 'fk_vunit_variant');
            $table->unique(['company_id', 'variant_id', 'code'], 'uq_vunit');
            $table->unique(['company_id', 'barcode'], 'uq_vunit_barcode');
        });
        Constraints::check('variant_units', 'ck_vunit_factor', 'factor >= 1');
        Constraints::check('variant_units', 'ck_vunit_code', "code REGEXP '^[a-z0-9_]{1,16}$'");

        Schema::create('product_images', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('product_id');
            $table->ulidColumn('variant_id')->nullable();
            $table->ulidColumn('file_id');
            $table->ulidColumn('thumb_file_id')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->auditColumns();

            $table->tenantForeign('product_id', 'products', 'fk_pimg_product');
            $table->tenantForeign('variant_id', 'product_variants', 'fk_pimg_variant');
            $table->tenantForeign('file_id', 'files', 'fk_pimg_file');
            $table->tenantForeign('thumb_file_id', 'files', 'fk_pimg_thumb');
            $table->index(['company_id', 'product_id', 'position'], 'ix_pimg');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('code', 16);
            $table->string('name', 64);
            $table->currencyCode('currency');
            $table->boolean('is_default')->default(false);
            $table->auditColumns();

            $table->unique(['company_id', 'code'], 'uq_pl');
            $table->foreign('currency', 'fk_pl_currency')->references('code')->on('currencies');
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('variant_id');
            $table->ulidColumn('price_list_id');
            $table->ulidColumn('unit_id');
            $table->money('price');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->auditColumns();

            $table->tenantForeign('variant_id', 'product_variants', 'fk_price_variant');
            $table->tenantForeign('price_list_id', 'price_lists', 'fk_price_list');
            $table->tenantForeign('unit_id', 'variant_units', 'fk_price_unit');
            $table->unique(['company_id', 'variant_id', 'price_list_id', 'unit_id', 'valid_from'], 'uq_price');
            $table->index(['company_id', 'variant_id', 'price_list_id', 'unit_id', 'valid_to'], 'ix_price_lookup');
        });
        Constraints::check('product_prices', 'ck_price_positive', 'price >= 0');
        Constraints::check('product_prices', 'ck_price_period', 'valid_to IS NULL OR valid_to >= valid_from');
    }

    public function down(): void
    {
        foreach (['product_prices', 'price_lists', 'product_images', 'variant_units', 'variant_attribute_values', 'product_variants', 'attribute_values', 'attributes', 'products', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
