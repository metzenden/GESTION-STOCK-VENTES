<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('disk', 16)->default('private');
            $table->string('path', 255)->unique('uq_files_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 96);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('purpose', 32);
            $table->auditColumns();

            $table->index(['company_id', 'purpose'], 'ix_files_purpose');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('file_id');
            $table->string('attachable_type', 64);
            $table->ulidColumn('attachable_id');
            $table->string('label', 160)->nullable();
            $table->auditColumns();

            $table->tenantForeign('file_id', 'files');
            $table->index(['company_id', 'attachable_type', 'attachable_id'], 'ix_attach_target');
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('doc_type', 24);
            $table->string('source_type', 64);
            $table->ulidColumn('source_id');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->ulidColumn('file_id');
            $table->char('content_sha256', 64);
            $table->auditColumns();

            $table->tenantForeign('file_id', 'files');
            $table->unique(['company_id', 'source_type', 'source_id', 'doc_type', 'revision'], 'uq_gendoc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('files');
    }
};
