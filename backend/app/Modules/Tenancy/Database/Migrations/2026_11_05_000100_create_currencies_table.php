<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->currencyCode('code')->primary();
            $table->string('name', 64);
            $table->unsignedTinyInteger('decimals');
            $table->string('symbol', 8);
        });

        Constraints::check('currencies', 'ck_currencies_decimals', 'decimals <= 4');

        // Données de référence (D-001) ; d'autres devises peuvent être ajoutées par le super-administrateur.
        DB::table('currencies')->insert([
            ['code' => 'XOF', 'name' => 'Franc CFA (BCEAO)', 'decimals' => 0, 'symbol' => 'FCFA'],
            ['code' => 'EUR', 'name' => 'Euro', 'decimals' => 2, 'symbol' => '€'],
            ['code' => 'USD', 'name' => 'Dollar américain', 'decimals' => 2, 'symbol' => '$'],
            ['code' => 'CNY', 'name' => 'Yuan renminbi', 'decimals' => 2, 'symbol' => '¥'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
