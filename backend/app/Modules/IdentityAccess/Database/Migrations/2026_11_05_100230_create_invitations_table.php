<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invitations (ADM-002) : jeton haché, usage unique, valable 72 h, révocable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->string('email', 190);
            $table->ulidColumn('role_id');
            $table->char('token_hash', 64)->unique('uq_invitations_token');
            $table->timestamp('expires_at', 6);
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->ulidColumn('user_id')->nullable();
            $table->auditColumns();

            $table->tenantForeign('role_id', 'roles', 'fk_invitations_role');
            $table->tenantForeign('user_id', 'users', 'fk_invitations_user');
            $table->index(['company_id', 'email'], 'ix_invitations_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
