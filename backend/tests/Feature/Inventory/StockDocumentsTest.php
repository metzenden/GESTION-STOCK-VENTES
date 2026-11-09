<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Inventory\Application\AdjustmentService;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L2-11 à L2-14 : ajustements, inventaires, transferts, consultation, rapprochement.
*/

function stockCompany(): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);

    return [$company, $admin, $variant, defaultWarehouse($company)];
}

function stockIdemKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

it('comptabilise un ajustement : entrée au coût du dernier lot, sortie sur un lot (INV-006, INV-007)', function () {
    [, $admin, $variant, $wh] = stockCompany();
    $lot = receiveStock($admin, $variant, 10, '750');

    $id = loginAs($admin)->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $wh, 'reason' => 'Casse et retrouvé', 'lines' => [
        ['variant_id' => $variant, 'quantity' => 3],
        ['variant_id' => $variant, 'lot_id' => $lot, 'quantity' => -2, 'kind' => 'damage'],
    ]])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    loginAs($admin)->postJson("/api/v1/stock-adjustments/{$id}/submit", [], stockIdemKey())
        ->assertOk()->assertJsonPath('data.status', 'posted');

    expect(position($wh, $variant))->toMatchArray(['on_hand' => 11])
        ->and(DB::table('stock_lots')->where('origin_type', 'adjustment')->value('unit_cost'))->toBe('750.00000000')
        ->and(DB::table('stock_movements')->where('movement_type', 'loss')->value('quantity'))->toBe(-2);
});

it('refuse une sortie d\'ajustement sur du stock réservé (INV-007)', function () {
    [$company, $admin, $variant, $wh] = stockCompany();
    $lot = receiveStock($admin, $variant, 5);
    actingAsCompany($company);
    DB::transaction(fn () => app(StockLedger::class)->reserve($wh, $variant, 4, 'sales_order_line', (string) Str::ulid()));

    $id = loginAs($admin)->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $wh, 'lines' => [['variant_id' => $variant, 'lot_id' => $lot, 'quantity' => -2]]])->json('data.id');

    loginAs($admin)->postJson("/api/v1/stock-adjustments/{$id}/submit", [], stockIdemKey())->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 5, 'reserved' => 4]);
});

it('bloque provisoirement les sorties d\'un ajustement soumis à approbation, puis applique ou libère (INV-005)', function () {
    [$company, $admin, $variant, $wh] = stockCompany();
    $lot = receiveStock($admin, $variant, 10);
    loginAs($admin)->postJson('/api/v1/approval-rules', ['name' => 'Tout ajustement', 'operation' => 'stock_adjustment', 'threshold_type' => 'always'])->assertCreated();

    $make = fn () => loginAs($admin)->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $wh, 'lines' => [['variant_id' => $variant, 'lot_id' => $lot, 'quantity' => -4]]])->json('data.id');

    $first = $make();
    loginAs($admin)->postJson("/api/v1/stock-adjustments/{$first}/submit", [], stockIdemKey())->assertStatus(202)->assertJsonPath('data.status', 'pending_approval');
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 10, 'blocked' => 4, 'available' => 6])
        ->and(DB::table('stock_movements')->where('source_id', $first)->count())->toBe(0);

    actingAsCompany($company);
    app('auth')->guard('web')->setUser($admin);
    app(AdjustmentService::class)->approve($first, $admin->id);
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 6, 'blocked' => 0]);

    $second = $make();
    loginAs($admin)->postJson("/api/v1/stock-adjustments/{$second}/submit", [], stockIdemKey())->assertStatus(202);
    app('auth')->guard('web')->setUser($admin);
    app(AdjustmentService::class)->reject($second, $admin->id);
    expect(position($wh, $variant))->toMatchArray(['on_hand' => 6, 'blocked' => 0, 'available' => 6]);
});

it('valide un inventaire : écart = compté − (théorique + mouvements depuis le figeage) (INV-101 à INV-103)', function () {
    [$company, $admin, $variant, $wh] = stockCompany();
    $lot = receiveStock($admin, $variant, 10);

    $inventory = loginAs($admin)->postJson('/api/v1/inventories', ['warehouse_id' => $wh])->assertCreated()->json('data.id');
    $lines = loginAs($admin)->postJson("/api/v1/inventories/{$inventory}/start", [], stockIdemKey())->assertOk()->assertJsonPath('data.status', 'counting')->json('data.lines');
    expect($lines)->toHaveCount(1)->and($lines[0]['qty_expected'])->toBe(10);

    // Vente pendant l'inventaire : 3 pièces sorties après le figeage (les articles restent vendables, INV-102).
    actingAsCompany($company);
    app('auth')->guard('web')->setUser($admin);
    DB::transaction(fn () => app(StockLedger::class)->issue($wh, $lot, 3, 'adjustment_out', new StockSource('test', (string) Str::ulid(), (string) Str::ulid()), now()));

    loginAs($admin)->putJson("/api/v1/inventories/{$inventory}/lines/{$lines[0]['id']}", ['qty_counted' => 6])
        ->assertOk()->assertJsonPath('data.lines.0.difference', -1)->assertJsonPath('data.lines.0.qty_moved_since_snapshot', -3);

    loginAs($admin)->postJson("/api/v1/inventories/{$inventory}/validate", [], stockIdemKey())->assertStatus(409);
    loginAs($admin)->postJson("/api/v1/inventories/{$inventory}/close-counting")->assertOk()->assertJsonPath('data.status', 'review');
    $validated = loginAs($admin)->postJson("/api/v1/inventories/{$inventory}/validate", [], stockIdemKey())->assertOk()->assertJsonPath('data.status', 'validated')->json('data');

    expect(position($wh, $variant)['on_hand'])->toBe(6)
        ->and(DB::table('stock_adjustments')->where('id', $validated['adjustment_id'])->value('status'))->toBe('posted');
});

it('n\'autorise qu\'un inventaire ouvert par dépôt et variante (INV-105)', function () {
    [, $admin, $variant, $wh] = stockCompany();
    receiveStock($admin, $variant, 2);

    $first = loginAs($admin)->postJson('/api/v1/inventories', ['warehouse_id' => $wh])->json('data.id');
    loginAs($admin)->postJson("/api/v1/inventories/{$first}/start", [], stockIdemKey())->assertOk();
    $second = loginAs($admin)->postJson('/api/v1/inventories', ['warehouse_id' => $wh, 'variant_ids' => [$variant]])->json('data.id');

    loginAs($admin)->postJson("/api/v1/inventories/{$second}/start", [], stockIdemKey())->assertStatus(409)->assertJsonPath('code', 'inventory_open');
});

it('transfère entre dépôts : réservation, transit, réception avec perte déclarée, total inchangé hors perte (TRF-001 à TRF-005)', function () {
    [, $admin, $variant, $from] = stockCompany();
    $to = loginAs($admin)->postJson('/api/v1/warehouses', ['code' => 'THIES', 'name' => 'Dépôt de Thiès'])->assertCreated()->json('data.id');
    receiveStock($admin, $variant, 6, '100', receivedAt: '2026-01-01');
    receiveStock($admin, $variant, 6, '200', receivedAt: '2026-02-01');

    $transfer = loginAs($admin)->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'lines' => [['variant_id' => $variant, 'quantity' => 10]]])
        ->assertCreated()->json('data.id');

    loginAs($admin)->postJson("/api/v1/stock-transfers/{$transfer}/confirm", [], stockIdemKey())->assertOk();
    expect(position($from, $variant))->toMatchArray(['reserved' => 10, 'available' => 2]);

    $lines = loginAs($admin)->postJson("/api/v1/stock-transfers/{$transfer}/ship", [], stockIdemKey())->assertOk()->assertJsonPath('data.status', 'in_transit')->json('data.lines');
    expect(collect($lines)->pluck('qty_sent')->all())->toBe([6, 4]);
    expect(position($from, $variant))->toMatchArray(['on_hand' => 2, 'reserved' => 0]);
    loginAs($admin)->getJson("/api/v1/stock/positions?filter[warehouse_id]={$to}")->assertOk();

    // 1 pièce manquante sur la seconde ligne à l'arrivée.
    loginAs($admin)->postJson("/api/v1/stock-transfers/{$transfer}/receive", ['received' => [$lines[1]['id'] => 3]], stockIdemKey())
        ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.1.qty_discrepancy', 1);

    expect(position($to, $variant)['on_hand'])->toBe(9)
        ->and(position($from, $variant)['on_hand'] + position($to, $variant)['on_hand'])->toBe(12 - 1)
        ->and(DB::table('stock_adjustments')->where('transfer_id', $transfer)->value('status'))->toBe('posted')
        ->and(DB::table('stock_movements')->where('movement_type', 'transfer_in')->sum('quantity'))->toEqual(10);
});

it('annule un transfert confirmé en libérant la réservation (TRF-006)', function () {
    [, $admin, $variant, $from] = stockCompany();
    $to = loginAs($admin)->postJson('/api/v1/warehouses', ['code' => 'B', 'name' => 'B'])->json('data.id');
    receiveStock($admin, $variant, 5);
    $transfer = loginAs($admin)->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'lines' => [['variant_id' => $variant, 'quantity' => 5]]])->json('data.id');
    loginAs($admin)->postJson("/api/v1/stock-transfers/{$transfer}/confirm", [], stockIdemKey())->assertOk();

    loginAs($admin)->postJson("/api/v1/stock-transfers/{$transfer}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(position($from, $variant))->toMatchArray(['reserved' => 0, 'available' => 5]);

    loginAs($admin)->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $from, 'to_warehouse_id' => $from, 'lines' => [['variant_id' => $variant, 'quantity' => 1]]])
        ->assertStatus(422);
});

it('affiche les compartiments sans double soustraction et signale le stock faible (L2-14)', function () {
    [$company, $admin, $variant, $wh] = stockCompany();
    receiveStock($admin, $variant, 10);
    actingAsCompany($company);
    DB::transaction(fn () => app(StockLedger::class)->reserve($wh, $variant, 7, 'sales_order_line', (string) Str::ulid()));
    DB::table('product_variants')->where('id', $variant)->update(['low_stock_threshold' => 3]);

    loginAs($admin)->getJson('/api/v1/stock/positions')->assertOk()
        ->assertJsonPath('data.0.on_hand', 10)->assertJsonPath('data.0.reserved', 7)->assertJsonPath('data.0.available', 3)
        ->assertJsonPath('data.0.is_low', true);
    expect(loginAs($admin)->getJson('/api/v1/stock/positions?filter[low]=1')->json('meta.total'))->toBe(1);
});

it('masque les coûts sans la permission sensible (GEN-005)', function () {
    [$company, $admin, $variant] = stockCompany();
    receiveStock($admin, $variant, 4, '1234');
    $storekeeper = userWithRole($company, RoleTemplate::Storekeeper);

    $lots = loginAs($storekeeper)->getJson('/api/v1/stock/lots')->assertOk();
    expect($lots->json('data.0'))->not->toHaveKey('unit_cost')->and($lots->json('meta.redacted'))->toBe(['unit_cost']);
    expect(loginAs($storekeeper)->getJson('/api/v1/stock/movements')->json('data.0'))->not->toHaveKey('total_cost');
    loginAs($storekeeper)->getJson('/api/v1/stock/valuation')->assertForbidden();

    loginAs($admin)->getJson('/api/v1/stock/lots')->assertJsonPath('data.0.unit_cost.amount', '1234');
    loginAs($admin)->getJson('/api/v1/stock/valuation')->assertOk()->assertJsonPath('data.total', ['amount' => '4936', 'currency' => 'XOF']);
});

it('détecte un écart injecté lors du rapprochement (INV-008)', function () {
    [, $admin, $variant, $wh] = stockCompany();
    receiveStock($admin, $variant, 8);

    expect(Artisan::call('inventory:reconcile'))->toBe(0);

    DB::table('stock_positions')->where(['warehouse_id' => $wh, 'variant_id' => $variant])->update(['qty_on_hand' => 9]);

    expect(Artisan::call('inventory:reconcile'))->toBe(1)
        ->and(Artisan::output())->toContain('position.on_hand')->toContain('attendu 8, constaté 9');
});

it('crée le dépôt principal à la création d\'entreprise et gère le dépôt par défaut', function () {
    [, $admin, , $wh] = stockCompany();

    loginAs($admin)->getJson('/api/v1/warehouses')->assertOk()->assertJsonPath('data.0.id', $wh)->assertJsonPath('data.0.is_default', true);
    $other = loginAs($admin)->postJson('/api/v1/warehouses', ['code' => 'NORD', 'name' => 'Nord', 'is_default' => true])->assertCreated()->json('data');

    expect($other['is_default'])->toBeTrue();
    loginAs($admin)->patchJson("/api/v1/warehouses/{$other['id']}", ['status' => 'inactive'])->assertStatus(422);
    loginAs($admin)->patchJson("/api/v1/warehouses/{$wh}", ['status' => 'inactive'])->assertOk();
});
