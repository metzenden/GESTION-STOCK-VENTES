<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Utilisateurs (L1-04) : company_id NULL ⇔ super-administrateur ; une adresse e-mail = un compte = une entreprise. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->ulidColumn('company_id')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->string('name', 160);
            $table->string('email', 190);
            $table->timestamp('email_verified_at', 6)->nullable();
            $table->string('password', 255);
            $table->string('phone', 32)->nullable();
            $table->string('status', 16)->default('invited');
            $table->timestamp('last_login_at', 6)->nullable();
            $table->string('theme', 8)->default('system');
            $table->text('two_factor_secret')->nullable();
            $table->string('remember_token', 100)->nullable();
            // Incrémenté à la désactivation ou au changement de mot de passe : invalide toutes les sessions (ADM-003, D-069).
            $table->unsignedInteger('session_epoch')->default(0);
            $table->auditColumns();

            $table->unique('email', 'uq_users_email');
            $table->unique(['company_id', 'id'], 'uq_users_tenant');
            $table->foreign('company_id', 'fk_users_company')->references('id')->on('companies');
            $table->index(['company_id', 'status'], 'ix_users_company_status');
        });

        Constraints::check('users', 'ck_users_super_admin',
            '(is_super_admin = TRUE AND company_id IS NULL) OR (is_super_admin = FALSE AND company_id IS NOT NULL)');
        Constraints::check('users', 'ck_users_status', "status IN ('invited','active','disabled')");
        Constraints::check('users', 'ck_users_theme', "theme IN ('system','light','dark')");

        // Règle « une seule entreprise » : le rattachement ne change jamais.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_users_company_immutable BEFORE UPDATE ON users
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.company_id <=> OLD.company_id) OR NEW.is_super_admin <> OLD.is_super_admin THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le rattachement d''un utilisateur à son entreprise est immuable';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_users_company_immutable');
        Schema::dropIfExists('users');
    }
};
