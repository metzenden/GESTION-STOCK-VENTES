<?php

declare(strict_types=1);

use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tables créées par tests/Support/Migrations (environnement de test uniquement).

it('D-003 interdit en base une relation entre deux entreprises', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $parentOfB = (string) Str::ulid();

    DB::table('test_parents')->insert(['id' => $parentOfB, 'company_id' => $b->id]);

    DB::table('test_children')->insert(['id' => (string) Str::ulid(), 'company_id' => $a->id, 'parent_id' => $parentOfB]);
})->throws(QueryException::class);

it('accepte une relation au sein de la même entreprise', function () {
    $a = Company::factory()->create();
    $parent = (string) Str::ulid();

    DB::table('test_parents')->insert(['id' => $parent, 'company_id' => $a->id]);
    DB::table('test_children')->insert(['id' => (string) Str::ulid(), 'company_id' => $a->id, 'parent_id' => $parent]);

    expect(DB::table('test_children')->count())->toBe(1);
});

it('D-038 interdit la modification d\'une table en ajout seul', function () {
    $id = (string) Str::ulid();
    DB::table('test_journal')->insert(['id' => $id, 'quantity' => 5]);

    DB::table('test_journal')->where('id', $id)->update(['quantity' => 6]);
})->throws(QueryException::class, 'ajout seul');

it('D-038 interdit la suppression dans une table en ajout seul', function () {
    $id = (string) Str::ulid();
    DB::table('test_journal')->insert(['id' => $id, 'quantity' => 5]);

    DB::table('test_journal')->where('id', $id)->delete();
})->throws(QueryException::class, 'ajout seul');

it('applique les contraintes CHECK', function () {
    DB::table('test_journal')->insert(['id' => (string) Str::ulid(), 'quantity' => 0]);
})->throws(QueryException::class);

it('applique le mode SQL strict sur la connexion applicative', function () {
    $mode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;

    expect($mode)->toContain('STRICT_');
});
