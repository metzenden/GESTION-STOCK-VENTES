<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->ulidColumn('company_id')->nullable();
            $table->ulidColumn('user_id')->nullable();
            // Propriétaire de la clé : identifiant utilisateur, ou « anon:<ip> » pour les routes publiques.
            $table->string('owner', 80);
            $table->string('idempotency_key', 64);
            $table->string('route', 160);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->timestamp('locked_at', 6)->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique(['owner', 'idempotency_key'], 'uq_idem_owner_key');
            $table->index('expires_at', 'ix_idem_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
