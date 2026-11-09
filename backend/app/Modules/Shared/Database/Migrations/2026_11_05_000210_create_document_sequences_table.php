<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('doc_type', 24);
            $table->unsignedSmallInteger('period_year');
            $table->string('prefix', 12);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->unsignedInteger('next_number')->default(1);
            $table->auditColumns();

            $table->unique(['company_id', 'doc_type', 'period_year'], 'uq_docseq');
        });

        Constraints::check('document_sequences', 'ck_docseq_next', 'next_number >= 1');
        Constraints::check('document_sequences', 'ck_docseq_padding', 'padding BETWEEN 1 AND 10');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
