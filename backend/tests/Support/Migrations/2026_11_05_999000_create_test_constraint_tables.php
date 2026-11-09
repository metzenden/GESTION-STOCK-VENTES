<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Tables de test (environnement « testing » uniquement) servant à vérifier les conventions
| de schéma : FK composites d'entreprise, tables en ajout seul, contraintes CHECK.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_parents', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
        });

        Schema::create('test_children', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->tenant();
            $table->ulidColumn('parent_id');
            $table->tenantForeign('parent_id', 'test_parents');
        });

        Schema::create('test_journal', function (Blueprint $table) {
            $table->ulidPrimary();
            $table->bigInteger('quantity');
        });

        Constraints::appendOnly('test_journal');
        Constraints::check('test_journal', 'ck_test_journal_qty', 'quantity <> 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('test_journal');
        Schema::dropIfExists('test_children');
        Schema::dropIfExists('test_parents');
    }
};
