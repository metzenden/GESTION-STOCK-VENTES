<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Journal d'audit en ajout seul (L1-08, D-038). company_id NULL = action de plateforme. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->ulidColumn('company_id')->nullable();
            $table->ulidColumn('user_id')->nullable();
            $table->ulidColumn('impersonated_company_id')->nullable();
            $table->string('action', 96);
            $table->string('subject_type', 64)->nullable();
            $table->ulidColumn('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 26)->charset('ascii')->nullable();
            $table->timestamp('occurred_at', 6)->useCurrent();

            $table->foreign('company_id', 'fk_audit_company')->references('id')->on('companies');
            $table->index(['company_id', 'occurred_at'], 'ix_audit_company_time');
            $table->index(['company_id', 'subject_type', 'subject_id'], 'ix_audit_subject');
            $table->index(['user_id', 'occurred_at'], 'ix_audit_user');
            $table->index(['company_id', 'action', 'occurred_at'], 'ix_audit_action');
        });

        Constraints::appendOnly('audit_logs');
    }

    public function down(): void
    {
        Constraints::dropAppendOnly('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
