<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Moteur d'approbation (docs/03 §13, L6-01) et notifications in-app (L6-03). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('name', 120);
            $table->string('operation', 32);
            $table->ulidColumn('role_id')->nullable();
            $table->string('threshold_type', 16)->default('always');
            $table->decimal('threshold_value', 19, 4)->nullable();
            $table->string('approver_permission', 64)->default('approvals.approve');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->tenantForeign('role_id', 'roles', 'fk_aprule_role');
            $table->index(['company_id', 'operation', 'is_active'], 'ix_aprule_op');
        });
        Constraints::check('approval_rules', 'ck_aprule_op', "operation IN ('discount','price_override','cancellation','stock_adjustment','credit_note','financial_correction')");
        Constraints::check('approval_rules', 'ck_aprule_threshold', "threshold_type IN ('always','amount','percent','quantity') AND (threshold_type = 'always' OR threshold_value IS NOT NULL)");

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('number', 32);
            $table->string('operation', 40);
            $table->string('subject_type', 40);
            $table->ulidColumn('subject_id');
            $table->ulidColumn('rule_id')->nullable();
            $table->string('approver_permission', 64);
            $table->string('summary', 255);
            $table->json('context');
            $table->string('status', 16)->default('pending');
            $table->ulidColumn('requested_by')->nullable();
            $table->ulidColumn('decided_by')->nullable();
            $table->timestamp('decided_at', 6)->nullable();
            $table->string('comment', 500)->nullable();
            $table->boolean('self_approved')->default(false);
            $table->string('last_error', 500)->nullable();
            // APR-010 : une seule demande ouverte par (sujet, opération).
            $table->string('open_key', 100)->nullable()->storedAs("IF(status = 'pending', CONCAT(subject_id, ':', operation), NULL)");
            $table->auditColumns();

            $table->tenantForeign('rule_id', 'approval_rules', 'fk_apreq_rule');
            $table->unique(['company_id', 'number'], 'uq_apreq_number');
            $table->unique(['company_id', 'open_key'], 'uq_apreq_open');
            $table->index(['company_id', 'status', 'created_at'], 'ix_apreq_status');
        });
        Constraints::check('approval_requests', 'ck_apreq_status', "status IN ('pending','approved','rejected','cancelled')");

        Schema::create('notifications_inbox', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('user_id');
            $table->string('type', 40);
            $table->string('title', 160);
            $table->string('body', 500)->nullable();
            $table->string('link', 255)->nullable();
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->tenantForeign('user_id', 'users', 'fk_notif_user');
            $table->index(['company_id', 'user_id', 'read_at'], 'ix_notif_user');
        });
    }

    public function down(): void
    {
        foreach (['notifications_inbox', 'approval_requests', 'approval_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
