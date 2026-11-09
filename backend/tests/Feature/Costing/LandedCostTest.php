<?php

declare(strict_types=1);

use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Shared\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L3-10 à L3-13 : frais d'approche, calcul (TEST-009, CR-001 à CR-010), finalisation (CR-004, CR-013).
*/

function costKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

/** Arrivage en XOF : A = 100 pièces à 1 000 (2 m³), B = 300 pièces à 500 (3 m³), entièrement reçu. */
function receivedShipment(): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $wh = defaultWarehouse($company);
    [$a, $b] = [createVariant($company), createVariant($company)];
    $shipment = loginAs($admin)->postJson('/api/v1/shipments', ['default_warehouse_id' => $wh])->json('data.id');
    $lines = loginAs($admin)->postJson("/api/v1/shipments/{$shipment}/lines", ['lines' => [
        ['variant_id' => $a, 'qty_expected' => 100, 'cbm_total' => '2', 'purchase_currency' => 'XOF', 'purchase_unit_price' => '1000', 'pieces_per_carton' => 10],
        ['variant_id' => $b, 'qty_expected' => 300, 'cbm_total' => '3', 'purchase_currency' => 'XOF', 'purchase_unit_price' => '500'],
    ]])->assertCreated()->json('data.lines');

    foreach (['shipped', 'arrived'] as $to) {
        loginAs($admin)->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => $to], costKey())->assertOk();
    }

    $receipt = loginAs($admin)->postJson('/api/v1/goods-receipts', ['shipment_id' => $shipment, 'warehouse_id' => $wh])->json('data');

    foreach ($receipt['lines'] as $line) {
        loginAs($admin)->putJson("/api/v1/goods-receipts/{$receipt['id']}/lines/{$line['id']}", ['qty_accepted' => $line['qty_expected']])->assertOk();
    }

    $validated = loginAs($admin)->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], costKey())->assertOk()->json('data');

    return [
        'company' => $company, 'admin' => $admin, 'wh' => $wh, 'shipment' => $shipment, 'lines' => $lines,
        'lotA' => $validated['lines'][0]['lot_id'], 'lotB' => $validated['lines'][1]['lot_id'],
    ];
}

function freight(array $ctx, array $data = []): array
{
    $category = DB::table('landed_cost_categories')->where('company_id', $ctx['company']->id)->where('code', 'freight')->value('id')
        ?? loginAs($ctx['admin'])->getJson('/api/v1/landed-cost-categories')->json('data.0.id');

    return loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs", $data + [
        'category_id' => $category, 'currency' => 'XOF', 'amount' => '500000', 'description' => 'Fret maritime',
    ])->assertCreated()->json('data');
}

it('calcule, bloque sur anomalie, puis finalise au coût de revient de référence (TEST-009, TEST-010)', function () {
    $ctx = receivedShipment();
    $forwarder = loginAs($ctx['admin'])->postJson('/api/v1/suppliers', ['code' => 'TR-1', 'name' => 'Transitaire', 'supplier_type' => 'forwarder', 'currency' => 'XOF'])->json('data.id');
    $fee = freight($ctx, ['supplier_id' => $forwarder]);

    // Frais non confirmé : le calcul est possible, la finalisation bloquée (CR-006).
    $draft = loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->assertCreated()->json('data');
    expect(array_column($draft['blocking_issues'], 'code'))->toBe(['fee_not_confirmed']);
    loginAs($ctx['admin'])->postJson("/api/v1/landed-costs/{$draft['id']}/finalize", [], costKey())->assertStatus(422)->assertJsonPath('code', 'landed_cost_blocked');

    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs/{$fee['id']}/confirm", [], costKey())->assertOk()->assertJsonPath('data.status', 'confirmed');
    // Engagement du transitaire (D-026), jamais une dépense (CR-012).
    expect(DB::table('supplier_bills')->where('supplier_id', $forwarder)->value('amount'))->toBe('500000.0000');

    $calc = loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->assertCreated()->json('data');
    expect($calc['revision'])->toBe(2)->and($calc['blocking_issues'])->toBe([])
        ->and(array_column($calc['lines'], 'allocated_common_fees'))->toBe(['200000.00000000', '300000.00000000'])
        ->and(array_column($calc['lines'], 'unit_cost_piece'))->toBe(['3000.00000000', '1500.00000000'])
        ->and($calc['lines'][0]['unit_cost_carton'])->toBe('30000.00000000')
        ->and($calc['total_cost'])->toBe('750000.0000')
        ->and(DB::table('landed_cost_calculations')->where('id', $draft['id'])->value('status'))->toBe('superseded');

    // Clôture refusée tant que le coût n'est pas définitif.
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/transition", ['to' => 'closed'], costKey())->assertStatus(422)->assertJsonPath('code', 'landed_cost_not_final');

    loginAs($ctx['admin'])->postJson("/api/v1/landed-costs/{$calc['id']}/finalize", [], costKey())->assertOk()->assertJsonPath('data.status', 'final');

    $lot = DB::table('stock_lots')->where('id', $ctx['lotA'])->first();
    expect($lot->cost_status)->toBe('final')->and($lot->unit_cost)->toBe('3000.00000000')->and($lot->landed_cost_calculation_id)->toBe($calc['id']);

    // CR-002 : un seul calcul définitif ; frais figés.
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->assertStatus(409);
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs/{$fee['id']}/cancel")->assertStatus(409);

    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/transition", ['to' => 'closed'], costKey())->assertOk()->assertJsonPath('data.status', 'closed');
});

it('valorise l\'écart des sorties déjà valorisées au coût provisoire (CR-013)', function () {
    $ctx = receivedShipment();
    // Sortie de 10 pièces du lot A au coût provisoire (1 000, aucun frais connu à la réception).
    app('auth')->guard('web')->setUser($ctx['admin']);
    app(TenantContext::class)->runAs($ctx['company']->id, fn () => DB::transaction(fn () => app(StockLedger::class)
        ->issue($ctx['wh'], $ctx['lotA'], 10, 'loss', new StockSource('test', (string) Str::ulid(), (string) Str::ulid()), CarbonImmutable::now())));
    expect(DB::table('stock_lots')->where('id', $ctx['lotA'])->value('unit_cost'))->toBe('1000.00000000');

    $fee = freight($ctx);
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs/{$fee['id']}/confirm", [], costKey())->assertOk();
    $calc = loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->json('data');
    $final = loginAs($ctx['admin'])->postJson("/api/v1/landed-costs/{$calc['id']}/finalize", [], costKey())->assertOk()->json('data');

    expect($final['valuation_adjustments'])->toHaveCount(1)
        ->and($final['valuation_adjustments'][0])->toMatchArray(['quantity' => 10, 'old_unit_cost' => '1000.00000000', 'new_unit_cost' => '3000.00000000', 'delta_amount' => '20000.0000'])
        // Un lot finalisé conserve son coût (CR-004) : la base refuse toute modification ultérieure.
        ->and(fn () => DB::table('stock_lots')->where('id', $ctx['lotA'])->update(['unit_cost' => '1']))->toThrow(QueryException::class);
});

it('refuse de finaliser un calcul dont les entrées ont changé (reproductibilité)', function () {
    $ctx = receivedShipment();
    $fee = freight($ctx);
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs/{$fee['id']}/confirm", [], costKey())->assertOk();
    $calc = loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->json('data');

    $again = loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/landed-costs", [], costKey())->json('data');
    expect($again['inputs_hash'])->toBe($calc['inputs_hash'])
        ->and(array_map(fn ($l) => array_diff_key($l, ['id' => true]), $again['lines']))->toEqual(array_map(fn ($l) => array_diff_key($l, ['id' => true]), $calc['lines']));

    $extra = freight($ctx, ['amount' => '1000', 'description' => 'Manutention']);
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']}/costs/{$extra['id']}/confirm", [], costKey())->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/landed-costs/{$again['id']}/finalize", [], costKey())->assertStatus(409)->assertJsonPath('code', 'landed_cost_stale');
});

it('refuse d\'enregistrer un calcul sans volume (CR-001) et signale le taux manquant (CR-006)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $wh = defaultWarehouse($company);
    $variant = createVariant($company);
    $shipment = loginAs($admin)->postJson('/api/v1/shipments', ['default_warehouse_id' => $wh])->json('data.id');
    loginAs($admin)->postJson("/api/v1/shipments/{$shipment}/lines", ['lines' => [
        ['variant_id' => $variant, 'qty_expected' => 10, 'purchase_currency' => 'XOF', 'purchase_unit_price' => '100'],
    ]])->assertCreated();

    loginAs($admin)->postJson("/api/v1/shipments/{$shipment}/landed-costs", [], costKey())->assertStatus(422)->assertJsonPath('code', 'landed_cost_blocked');
    expect(DB::table('landed_cost_calculations')->where('shipment_id', $shipment)->count())->toBe(0);
});

it('gère les versions de formule : test obligatoire, version active immuable (CR-005, CR-014)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $standard = loginAs($admin)->getJson('/api/v1/cost-models')->assertOk()->json('data.0');
    expect($standard)->toMatchArray(['code' => 'STANDARD', 'status' => 'active', 'expression' => 'purchase_converted + common_fees + direct_fees']);

    loginAs($admin)->postJson('/api/v1/cost-models', ['code' => 'BAD', 'name' => 'x', 'expression' => 'purchase_converted + system(1)'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/expression');

    $v2 = loginAs($admin)->postJson("/api/v1/cost-models/{$standard['id']}/versions", ['expression' => 'purchase_converted * 1.02 + common_fees + direct_fees'])
        ->assertCreated()->assertJsonPath('data.version', 2)->json('data');
    loginAs($admin)->postJson("/api/v1/cost-models/{$v2['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'model_not_tested');
    loginAs($admin)->postJson("/api/v1/cost-models/{$v2['id']}/test", ['sample' => ['purchase_converted' => '100', 'common_fees' => '10', 'direct_fees' => '0']])
        ->assertOk()->assertJsonPath('data.result', '112.00');
    loginAs($admin)->postJson("/api/v1/cost-models/{$v2['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');

    expect(DB::table('landed_cost_models')->where('id', $standard['id'])->value('status'))->toBe('retired')
        ->and(fn () => DB::table('landed_cost_models')->where('id', $v2['id'])->update(['expression' => '0']))->toThrow(QueryException::class);
});
