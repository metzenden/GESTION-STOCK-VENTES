<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Packing lists et leurs lignes extraites (docs/03 §8, L3-06) ; une packing list ne modifie jamais le stock (IMP-001). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_lists', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('shipment_id');
            $table->ulidColumn('supplier_id')->nullable();
            $table->ulidColumn('file_id');
            $table->ulidColumn('mapping_template_id')->nullable();
            $table->string('file_format', 8);
            $table->currencyCode('currency');
            $table->string('status', 16)->default('uploaded');
            $table->json('parse_report')->nullable();
            $table->timestamp('validated_at', 6)->nullable();
            $table->ulidColumn('validated_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->auditColumns();

            $table->tenantForeign('shipment_id', 'shipments', 'fk_pkl_shipment');
            $table->tenantForeign('supplier_id', 'suppliers', 'fk_pkl_supplier');
            $table->tenantForeign('file_id', 'files', 'fk_pkl_file');
            $table->tenantForeign('mapping_template_id', 'import_mapping_templates', 'fk_pkl_template');
            $table->foreign('currency', 'fk_pkl_currency')->references('code')->on('currencies');
            $table->index(['company_id', 'shipment_id'], 'ix_pkl');
        });
        Constraints::check('packing_lists', 'ck_pkl_status', "status IN ('uploaded','in_review','validated','rejected')");

        Schema::create('packing_list_lines', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('packing_list_id');
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->string('supplier_reference', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('cartons')->nullable();
            $table->unsignedInteger('pieces_per_carton')->nullable();
            $table->quantity('total_qty')->nullable();
            $table->decimal('cbm', 14, 6)->nullable();
            $table->unitCost('unit_price')->nullable();
            $table->money('amount')->nullable();
            $table->ulidColumn('matched_variant_id')->nullable();
            $table->string('match_status', 16)->default('unmatched');
            $table->json('errors')->nullable();
            $table->auditColumns();

            $table->tenantForeign('packing_list_id', 'packing_lists', 'fk_pkll_pl');
            $table->tenantForeign('matched_variant_id', 'product_variants', 'fk_pkll_variant');
            $table->unique(['company_id', 'packing_list_id', 'row_number'], 'uq_pkll');
        });
        Constraints::check('packing_list_lines', 'ck_pkll_status', "match_status IN ('unmatched','suggested','confirmed','new_product','ignored')");
        Constraints::check('packing_list_lines', 'ck_pkll_values', '(total_qty IS NULL OR total_qty > 0) AND (cbm IS NULL OR cbm > 0) AND (unit_price IS NULL OR unit_price >= 0)');

        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->foreign(['company_id', 'packing_list_line_id'], 'fk_shl_pkll')->references(['company_id', 'id'])->on('packing_list_lines');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_lines', fn (Blueprint $table) => $table->dropForeign('fk_shl_pkll'));
        Schema::dropIfExists('packing_list_lines');
        Schema::dropIfExists('packing_lists');
    }
};
