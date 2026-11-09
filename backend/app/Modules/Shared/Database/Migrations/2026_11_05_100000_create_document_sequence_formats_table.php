<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format de numérotation choisi par l'entreprise pour chaque type de document (L1-14) :
 * préfixe et largeur, appliqués aux séquences créées ensuite et à celles de l'année en cours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequence_formats', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('doc_type', 24);
            $table->string('prefix', 12);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->auditColumns();

            $table->unique(['company_id', 'doc_type'], 'uq_docfmt');
        });

        Constraints::check('document_sequence_formats', 'ck_docfmt_padding', 'padding BETWEEN 1 AND 10');
        Constraints::check('document_sequence_formats', 'ck_docfmt_prefix', "prefix REGEXP '^[A-Z0-9]{1,12}$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequence_formats');
    }
};
