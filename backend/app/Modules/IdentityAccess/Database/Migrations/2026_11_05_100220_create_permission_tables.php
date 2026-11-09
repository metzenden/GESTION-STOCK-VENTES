<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Infrastructure\Permissions\PermissionCatalogSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et permissions (spatie/laravel-permission, mode teams : équipe = entreprise, D-006).
 * model_has_permissions existe parce que spatie la lit, mais reste vide : les permissions ne sont
 * accordées que par des rôles (auditabilité, D-070).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 96);
            $table->string('guard_name', 32)->default('web');
            $table->string('module', 32);
            $table->string('label', 160);
            $table->boolean('is_sensitive')->default(false);

            $table->unique(['name', 'guard_name'], 'uq_permissions_name');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->ulidColumn('company_id')->nullable();
            $table->string('name', 64);
            $table->string('guard_name', 32)->default('web');
            $table->boolean('is_system')->default(false);
            $table->string('template_code', 32)->nullable();
            $table->auditColumns();

            $table->unique(['company_id', 'name', 'guard_name'], 'uq_roles_name');
            $table->unique(['company_id', 'id'], 'uq_roles_tenant');
            $table->foreign('company_id', 'fk_roles_company')->references('id')->on('companies');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->ulidColumn('role_id');

            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id', 'fk_rhp_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id', 'fk_rhp_role')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->ulidColumn('role_id');
            $table->string('model_type', 128);
            $table->ulidColumn('model_id');
            $table->ulidColumn('company_id');

            $table->primary(['company_id', 'role_id', 'model_id', 'model_type']);
            // Un rôle attribué appartient obligatoirement à l'entreprise de l'attribution (D-003).
            $table->foreign(['company_id', 'role_id'], 'fk_mhr_role')->references(['company_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->foreign(['company_id', 'model_id'], 'fk_mhr_user')->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
            $table->index(['model_id', 'model_type'], 'ix_mhr_model');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 128);
            $table->ulidColumn('model_id');
            $table->ulidColumn('company_id');

            $table->primary(['company_id', 'permission_id', 'model_id', 'model_type']);
            $table->foreign('permission_id', 'fk_mhp_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign(['company_id', 'model_id'], 'fk_mhp_user')->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
        });

        app(PermissionCatalogSynchronizer::class)->sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
