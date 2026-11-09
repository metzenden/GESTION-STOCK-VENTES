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
        Schema::create('companies', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->string('code', 32)->unique('uq_companies_code');
            $table->string('trade_name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->string('ninea', 32)->nullable();
            $table->string('rccm', 64)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country_code', 2)->default('SN');
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            // FK vers files ajoutée au lot 1 (gestion du logo).
            $table->ulidColumn('logo_file_id')->nullable();
            $table->currencyCode('reference_currency');
            $table->string('timezone', 64)->default('Africa/Dakar');
            $table->string('status', 16)->default('provisioning');
            $table->timestamp('suspended_at', 6)->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->auditColumns();

            $table->foreign('reference_currency', 'fk_companies_currency')->references('code')->on('currencies');
            $table->index('status', 'ix_companies_status');
        });

        Constraints::check('companies', 'ck_companies_status', "status IN ('provisioning','active','suspended','archived')");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
