<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modèles de correspondance du moteur d'import (docs/03 §8, L3-05) — partagés avec les imports initiaux (L6-08). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_templates', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('import_type', 24);
            $table->ulidColumn('supplier_id')->nullable();
            $table->string('name', 120);
            $table->string('file_format', 8);
            $table->json('mapping');
            $table->auditColumns();

            $table->tenantForeign('supplier_id', 'suppliers', 'fk_imt_supplier');
            $table->unique(['company_id', 'import_type', 'name'], 'uq_imt');
        });
        Constraints::check('import_mapping_templates', 'ck_imt_type', "import_type IN ('packing_list','products','opening_stock','customers','suppliers')");
        Constraints::check('import_mapping_templates', 'ck_imt_format', "file_format IN ('xlsx','csv','pdf')");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_templates');
    }
};
