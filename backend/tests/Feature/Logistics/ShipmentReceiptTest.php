<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L3-04, L3-08, L3-09 : arrivages, réceptions (manuel, scan), validation unique (IMP-001 à IMP-010, ST-001).
*/

function logisticsKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

/** Commande USD confirmée de 100 pièces, arrivage rattaché et arrivé ; renvoie le contexte utile. */
function arrivedShipment(int $qty = 100, string $price = '2', ?string $cbm = '2'): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);
    $warehouse = defaultWarehouse($company);
    $supplier = loginAs($admin)->postJson('/api/v1/suppliers', ['code' => 'F-'.Str::random(4), 'name' => 'Fournisseur', 'currency' => 'USD'])->json('data.id');
    $order = loginAs($admin)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'estimated_rate' => '600',
        'lines' => [['variant_id' => $variant, 'qty_units' => $qty, 'unit_price' => $price]]])->json('data');
    loginAs($admin)->postJson("/api/v1/purchase-orders/{$order['id']}/confirm", [], logisticsKey())->assertOk();

    $shipment = loginAs($admin)->postJson('/api/v1/shipments', ['reference' => 'BL-778', 'default_warehouse_id' => $warehouse])->assertCreated()->json('data');
    $shipment = loginAs($admin)->postJson("/api/v1/shipments/{$shipment['id']}/purchase-orders", ['purchase_order_id' => $order['id']])->assertOk()->json('data');
    $line = $shipment['lines'][0];

    if ($cbm !== null) {
        loginAs($admin)->patchJson("/api/v1/shipments/{$shipment['id']}/lines/{$line['id']}", ['cbm_total' => $cbm])->assertOk();
    }

    loginAs($admin)->postJson("/api/v1/shipments/{$shipment['id']}/transition", ['to' => 'shipped'], logisticsKey())->assertOk();
    loginAs($admin)->postJson("/api/v1/shipments/{$shipment['id']}/transition", ['to' => 'arrived'], logisticsKey())->assertOk()->assertJsonPath('data.status', 'arrived');

    return compact('company', 'admin', 'variant', 'warehouse', 'supplier', 'order', 'shipment', 'line');
}

it('affiche le stock attendu sans le rendre vendable (ST-001)', function () {
    $ctx = arrivedShipment(100);
    receiveStock($ctx['admin'], $ctx['variant'], 5);

    $row = loginAs($ctx['admin'])->getJson("/api/v1/stock/positions?filter[variant_id]={$ctx['variant']}")->assertOk()->json('data.0');

    expect($row)->toMatchArray(['on_hand' => 5, 'available' => 5, 'expected' => 100]);
});

it('valide une réception une seule fois : lot provisoire, mouvement, endommagé bloqué (IMP-002 à IMP-005)', function () {
    $ctx = arrivedShipment(100);
    ['admin' => $admin, 'shipment' => $shipment, 'warehouse' => $wh, 'variant' => $variant] = $ctx;
    DB::table('exchange_rates')->insert(['id' => (string) Str::ulid(), 'company_id' => $ctx['company']->id, 'from_currency' => 'USD', 'to_currency' => 'XOF', 'rate' => '610', 'valid_on' => '2026-01-01']);

    $receipt = loginAs($admin)->postJson('/api/v1/goods-receipts', ['shipment_id' => $shipment['id'], 'warehouse_id' => $wh])->assertCreated()->json('data');
    expect($receipt['lines'][0]['qty_expected'])->toBe(100);
    expect(loginAs($admin)->getJson("/api/v1/shipments/{$shipment['id']}")->json('data.status'))->toBe('unloading');

    loginAs($admin)->putJson("/api/v1/goods-receipts/{$receipt['id']}/lines/{$receipt['lines'][0]['id']}", ['qty_accepted' => 90, 'qty_damaged' => 4])
        ->assertOk()->assertJsonPath('data.status', 'counting')->assertJsonPath('data.lines.0.qty_missing', 6);
    // IMP-002 : le comptage seul ne touche pas le stock.
    expect(position($wh, $variant)['on_hand'])->toBe(0);

    $validated = loginAs($admin)->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], logisticsKey())->assertOk()->json('data');
    $lot = DB::table('stock_lots')->where('id', $validated['lines'][0]['lot_id'])->first();

    expect($validated['status'])->toBe('validated')
        ->and(position($wh, $variant))->toMatchArray(['on_hand' => 94, 'blocked' => 4, 'available' => 90])
        ->and($lot->cost_status)->toBe('provisional')
        ->and($lot->unit_cost)->toBe('1220.00000000')
        ->and($lot->shipment_id)->toBe($shipment['id'])
        ->and(DB::table('stock_holds')->where('lot_id', $lot->id)->value('reason'))->toBe('damaged')
        ->and(DB::table('purchase_orders')->where('id', $ctx['order']['id'])->value('status'))->toBe('partially_received')
        ->and(loginAs($admin)->getJson("/api/v1/shipments/{$shipment['id']}")->json('data.status'))->toBe('partially_received');

    // IMP-004 : seconde validation refusée, aucun mouvement en double.
    loginAs($admin)->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], logisticsKey())->assertStatus(409)->assertJsonPath('code', 'already_processed');
    expect(DB::table('stock_movements')->where('source_id', $receipt['id'])->count())->toBe(1);

    // Clôture du reliquat : les manquants restent consultables (IMP-005).
    loginAs($admin)->postJson("/api/v1/shipments/{$shipment['id']}/transition", ['to' => 'received'], logisticsKey())->assertOk()->assertJsonPath('data.status', 'received');
    expect(loginAs($admin)->getJson("/api/v1/goods-receipts/{$receipt['id']}")->json('data.lines.0.qty_missing'))->toBe(6);
});

it('refuse un surplus sans la permission dédiée (IMP-008)', function () {
    $ctx = arrivedShipment(10);
    $storekeeper = userWithRole($ctx['company'], RoleTemplate::Storekeeper);
    DB::table('exchange_rates')->insert(['id' => (string) Str::ulid(), 'company_id' => $ctx['company']->id, 'from_currency' => 'USD', 'to_currency' => 'XOF', 'rate' => '600', 'valid_on' => '2026-01-01']);

    $receipt = loginAs($storekeeper)->postJson('/api/v1/goods-receipts', ['shipment_id' => $ctx['shipment']['id'], 'warehouse_id' => $ctx['warehouse']])->assertCreated()->json('data');
    loginAs($storekeeper)->putJson("/api/v1/goods-receipts/{$receipt['id']}/lines/{$receipt['lines'][0]['id']}", ['qty_accepted' => 12])->assertOk();

    loginAs($storekeeper)->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], logisticsKey())->assertForbidden()->assertJsonPath('code', 'surplus_not_allowed');
    expect(position($ctx['warehouse'], $ctx['variant'])['on_hand'])->toBe(0);

    loginAs($ctx['admin'])->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], logisticsKey())->assertOk();
    expect(position($ctx['warehouse'], $ctx['variant'])['on_hand'])->toBe(12)
        ->and(DB::table('purchase_orders')->where('id', $ctx['order']['id'])->value('status'))->toBe('received');
});

it('dédoublonne les scans répétés par client_event_id (IMP-010)', function () {
    $ctx = arrivedShipment(20);
    $barcode = '3700000'.random_int(100000, 999999);
    DB::table('variant_units')->where('variant_id', $ctx['variant'])->where('factor', 1)->update(['barcode' => $barcode]);
    $receipt = loginAs($ctx['admin'])->postJson('/api/v1/goods-receipts', ['shipment_id' => $ctx['shipment']['id'], 'warehouse_id' => $ctx['warehouse'], 'counting_mode' => 'scan'])->json('data');
    $url = "/api/v1/goods-receipts/{$receipt['id']}/scans";

    loginAs($ctx['admin'])->postJson($url, ['client_event_id' => 'evt-1', 'code' => $barcode, 'units' => 3])->assertCreated()->assertJsonPath('data.outcome', 'counted');
    loginAs($ctx['admin'])->postJson($url, ['client_event_id' => 'evt-1', 'code' => $barcode, 'units' => 3])->assertOk()->assertJsonPath('meta.duplicate', true);
    loginAs($ctx['admin'])->postJson($url, ['client_event_id' => 'evt-2', 'code' => $barcode, 'damaged' => true])->assertCreated();
    loginAs($ctx['admin'])->postJson($url, ['client_event_id' => 'evt-3', 'code' => 'INCONNU'])->assertCreated()->assertJsonPath('data.outcome', 'unknown');

    $line = loginAs($ctx['admin'])->getJson("/api/v1/goods-receipts/{$receipt['id']}")->json('data.lines.0');
    expect($line)->toMatchArray(['qty_accepted' => 3, 'qty_damaged' => 1, 'qty_counted' => 4]);
});

it('n\'autorise pas de modifier les articles attendus une fois le déchargement commencé', function () {
    $ctx = arrivedShipment(10);
    loginAs($ctx['admin'])->postJson('/api/v1/goods-receipts', ['shipment_id' => $ctx['shipment']['id'], 'warehouse_id' => $ctx['warehouse']])->assertCreated();

    loginAs($ctx['admin'])->patchJson("/api/v1/shipments/{$ctx['shipment']['id']}/lines/{$ctx['line']['id']}", ['qty_expected' => 5])->assertStatus(409);
    loginAs($ctx['admin'])->postJson("/api/v1/shipments/{$ctx['shipment']['id']}/transition", ['to' => 'cancelled'], logisticsKey())->assertStatus(409);
});

it('convertit un CBM par carton en volume total de ligne (CR-009)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);
    $shipment = loginAs($admin)->postJson('/api/v1/shipments', ['default_warehouse_id' => defaultWarehouse($company)])->json('data.id');

    $line = loginAs($admin)->postJson("/api/v1/shipments/{$shipment}/lines", ['lines' => [[
        'variant_id' => $variant, 'cartons' => 12, 'pieces_per_carton' => 24, 'cbm_per_carton' => '0.125', 'purchase_currency' => 'XOF', 'purchase_unit_price' => '500',
    ]]])->assertCreated()->json('data.lines.0');

    expect($line)->toMatchArray(['qty_expected' => 288, 'cbm_total' => '1.500000', 'purchase_amount' => '144000.0000']);
});

it('masque les prix d\'achat des arrivages sans accès aux commandes (GEN-005)', function () {
    $ctx = arrivedShipment(10);
    // Rôle sur mesure : arrivages visibles, commandes d'achat non.
    $viewer = userWithRole($ctx['company'], RoleTemplate::Seller);
    app(TenantContext::class)->runAs($ctx['company']->id, fn () => $viewer->givePermissionTo('shipments.view'));

    $response = loginAs($viewer->refresh())->getJson("/api/v1/shipments/{$ctx['shipment']['id']}")->assertOk();
    expect($response->json('data.lines.0'))->not->toHaveKey('purchase_unit_price')
        ->and($response->json('meta.redacted'))->toBe(['purchase_unit_price', 'purchase_amount']);
});
