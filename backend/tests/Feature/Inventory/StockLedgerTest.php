<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L2-07, L2-08 : grand livre de stock — compartiments, contraintes SQL, unicité des mouvements, FIFO.
*/

function ledgerSetup(): array
{
    $company = provisionedCompany();
    $user = userWithRole($company);
    $variant = createVariant($company);
    actingAsCompany($company);
    app('auth')->guard('web')->setUser($user);

    return [$company, $user, $variant, defaultWarehouse($company)];
}

function src(): StockSource
{
    $id = (string) Str::ulid();

    return new StockSource('test', $id, $id);
}

it('refuse une réservation supérieure au disponible sans rien écrire (TEST-005)', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    receiveStock($user, $variant, 5);

    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->reserve($wh, $variant, 6, 'sales_order_line', (string) Str::ulid())))
        ->toThrow(BusinessRuleViolation::class, 'Disponible : 5, demandé : 6.');

    expect(DB::table('stock_reservations')->count())->toBe(0)
        ->and(position($wh, $variant))->toMatchArray(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('2 cartons de 6 + 3 pièces : 15 réservées, expédiées puis remises (TEST-022)', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    $lot = receiveStock($user, $variant, 20);
    $qty = Quantity::fromUnits(2, 6)->plus(Quantity::of(3))->value;
    $line = (string) Str::ulid();
    $ledger = app(StockLedger::class);

    DB::transaction(fn () => $ledger->reserve($wh, $variant, $qty, 'sales_order_line', $line));
    expect(position($wh, $variant))->toMatchArray(['reserved' => 15, 'available' => 5]);

    DB::transaction(fn () => $ledger->ship('sales_order_line', $line, [$lot => 15]));
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 20, 'reserved' => 0, 'in_delivery' => 15, 'available' => 5]);

    DB::transaction(fn () => $ledger->deliver($wh, $variant, [$lot => 15], new StockSource('delivery', $line, $line), now()));
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 5, 'in_delivery' => 0, 'available' => 5])
        ->and((int) DB::table('stock_movements')->where('movement_type', 'sale_delivery')->sum('quantity'))->toBe(-15);
});

it('rejette en base un disponible négatif (CHECK)', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    receiveStock($user, $variant, 5);

    DB::table('stock_positions')->where(['warehouse_id' => $wh, 'variant_id' => $variant])->update(['qty_reserved' => 6]);
})->throws(QueryException::class, 'ck_pos_available');

it('rejette en base un second mouvement pour la même origine (IMP-004)', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    $lot = DB::transaction(fn () => app(StockLedger::class)->createLot($variant, 'opening', null, now(), 10, '500', 'XOF'));
    $source = src();

    DB::transaction(fn () => app(StockLedger::class)->receive($wh, $lot, 10, 'opening', $source, now()));
    DB::transaction(fn () => app(StockLedger::class)->receive($wh, $lot, 10, 'opening', $source, now()));
})->throws(QueryException::class, 'uq_mov_source');

it('interdit toute modification d\'un mouvement validé et corrige par contre-écriture (INV-004)', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    receiveStock($user, $variant, 10);
    $movement = DB::table('stock_movements')->value('id');

    expect(fn () => DB::table('stock_movements')->update(['quantity' => 99]))->toThrow(QueryException::class, 'ajout seul');

    DB::transaction(fn () => app(StockLedger::class)->reverse($movement, 'Saisie en double', now()));

    expect(position($wh, $variant)['on_hand'])->toBe(0)
        ->and(DB::table('stock_movements')->where('reverses_movement_id', $movement)->value('quantity'))->toBe(-10);
});

it('affecte les lots en FIFO et valorise chaque sortie au coût de son lot', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    $old = receiveStock($user, $variant, 10, '100', receivedAt: '2026-01-01');
    $mid = receiveStock($user, $variant, 10, '200', receivedAt: '2026-02-01');
    $new = receiveStock($user, $variant, 10, '300', receivedAt: '2026-03-01');

    expect(DB::transaction(fn () => app(StockLedger::class)->allocateFifo($wh, $variant, 25)))->toBe([$old => 10, $mid => 10, $new => 5]);
});

it('exclut des sorties les quantités bloquées et en livraison, puis libère le blocage', function () {
    [, $user, $variant, $wh] = ledgerSetup();
    $lot = receiveStock($user, $variant, 10);
    $ledger = app(StockLedger::class);

    $hold = DB::transaction(fn () => $ledger->hold($wh, $lot, 4, 'damaged', 'test', (string) Str::ulid()));
    expect(position($wh, $variant))->toMatchArray(['blocked' => 4, 'available' => 6]);
    expect(fn () => DB::transaction(fn () => $ledger->issue($wh, $lot, 7, 'loss', src(), now())))->toThrow(BusinessRuleViolation::class);

    DB::transaction(fn () => $ledger->releaseHold($hold));
    expect(position($wh, $variant))->toMatchArray(['blocked' => 0, 'available' => 10]);
});

it('marque le produit comme ayant un historique au premier mouvement', function () {
    [, $user, $variant] = ledgerSetup();
    receiveStock($user, $variant, 1);

    expect((bool) DB::table('products')->whereIn('id', DB::table('product_variants')->where('id', $variant)->select('product_id'))->value('has_history'))->toBeTrue();
});
