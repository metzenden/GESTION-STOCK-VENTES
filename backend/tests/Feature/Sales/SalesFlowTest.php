<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| L4-03 à L4-07, L4-09, L4-13 : commande, confirmation (réservation + facture), livraisons, annulations, vente comptoir.
*/

it('confirme une commande : 2 cartons + 3 pièces = 15 réservées, facture numérotée et figée (TEST-022, SAL-002, BIL-001)', function () {
    $ctx = salesSetup();
    expect($ctx['customer']['phone'])->toBe('+221771234567');

    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'unit_id' => $ctx['item']['carton'], 'qty_units' => 2],
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 3],
    ]])->assertCreated()->json('data');

    // Prix carton déduit du prix pièce × 6 (CAT-004).
    expect($order['lines'][0])->toMatchArray(['qty_base' => 12, 'unit_price' => '6000.0000', 'line_total' => '12000.0000'])
        ->and($order['total'])->toBe('15000.0000')
        ->and($order['status'])->toBe('draft')
        ->and(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(0);

    $confirmed = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->assertOk()->json('data');

    expect($confirmed['status'])->toBe('confirmed')
        ->and(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['reserved' => 15, 'available' => 85])
        ->and($confirmed['invoice']['number'])->toMatch('/^FAC-\d{4}-\d{4}$/')
        ->and($confirmed['invoice']['total'])->toBe('15000.0000');

    $invoice = DB::table('invoices')->where('id', $confirmed['invoice_id'])->first();
    expect($invoice->due_date)->toBe(CarbonImmutable::parse($invoice->issue_date)->addDays(15)->toDateString())
        ->and(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total' => '1']))->toThrow(QueryException::class);

    // SAL-003 : une commande confirmée ne se modifie plus.
    loginAs($ctx['admin'])->patchJson("/api/v1/sales-orders/{$order['id']}", ['notes' => 'x'])->assertStatus(409);
});

it('refuse la confirmation si une ligne manque de stock, sans rien écrire (CMD-004)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 101],
    ]])->json('data');

    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

    expect(DB::table('invoices')->where('company_id', $ctx['company']->id)->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $order['id'])->value('status'))->toBe('draft')
        ->and(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(0);
});

it('livre en deux fois : expédition, remise partielle, échec et retour au dépôt (DLV-001 à DLV-005, TEST-004)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'fulfilment_mode' => 'delivery',
        'lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 10]]])->json('data');
    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->assertOk();

    $d1 = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/deliveries", ['lines' => [$order['lines'][0]['id'] => 6]])->assertCreated()->json('data');
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d1['id']}/ready")->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d1['id']}/ship", [], salesKey())->assertOk()->assertJsonPath('data.status', 'in_delivery');
    expect(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['reserved' => 4, 'in_delivery' => 6, 'on_hand' => 100]);

    // Remise de 5 sur 6 : 1 redevient réservée.
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d1['id']}/deliver", ['received' => [$d1['lines'][0]['id'] => 5]], salesKey())->assertOk();
    expect(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['on_hand' => 95, 'reserved' => 5, 'in_delivery' => 0])
        ->and(DB::table('stock_movements')->where('source_id', $d1['id'])->where('movement_type', 'sale_delivery')->value('quantity'))->toBe(-5)
        ->and(DB::table('stock_movements')->where('source_id', $d1['id'])->value('unit_cost'))->toBe('600.00000000')
        ->and(loginAs($ctx['admin'])->getJson("/api/v1/sales-orders/{$order['id']}")->json('data.status'))->toBe('partially_delivered');

    $d2 = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/deliveries")->assertCreated()->json('data');
    expect($d2['lines'][0]['quantity'])->toBe(5);
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/ready")->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/ship", [], salesKey())->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/fail", ['reason' => 'Client absent'], salesKey())->assertOk()->assertJsonPath('data.status', 'failed');
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/return-to-warehouse", [], salesKey())->assertOk()->assertJsonPath('data.attempt', 2);
    expect(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['reserved' => 5, 'in_delivery' => 0]);

    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/ship", [], salesKey())->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d2['id']}/deliver", [], salesKey())->assertOk();

    expect(loginAs($ctx['admin'])->getJson("/api/v1/sales-orders/{$order['id']}")->json('data.status'))->toBe('delivered')
        ->and(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['on_hand' => 90, 'reserved' => 0, 'available' => 90]);
});

it('annule une commande confirmée : réservations libérées, avoir d\'annulation, facture annulée (SAL-004, BIL-003)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 4]]])->json('data');
    $invoiceId = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->json('data.invoice_id');

    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/cancel", ['reason' => 'Client a renoncé'], salesKey())->assertOk()->assertJsonPath('data.status', 'cancelled');

    $note = DB::table('credit_notes')->where('origin_invoice_id', $invoiceId)->first();
    expect(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(0)
        ->and($note->kind)->toBe('cancellation')
        ->and($note->number)->toMatch('/^AV-\d{4}-\d{4}$/')
        ->and($note->total)->toBe('4000.0000')
        ->and($note->amount_applied)->toBe('4000.0000')
        ->and(DB::table('invoices')->where('id', $invoiceId)->first())->toMatchObject(['status' => 'cancelled', 'balance_due' => '0.0000']);
});

it('annule le reliquat d\'une commande partiellement livrée par avoir partiel (SAL-005)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 10]]])->json('data');
    $invoiceId = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->json('data.invoice_id');
    $d = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/deliveries", ['lines' => [$order['lines'][0]['id'] => 4]])->json('data');
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d['id']}/ready")->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d['id']}/deliver", [], salesKey())->assertOk();

    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/cancel", ['reason' => 'x'], salesKey())->assertStatus(409)->assertJsonPath('code', 'order_partially_delivered');
    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/cancel-remaining", ['reason' => 'Rupture fournisseur'], salesKey())->assertOk()->assertJsonPath('data.status', 'delivered');

    expect(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['on_hand' => 96, 'reserved' => 0])
        ->and(DB::table('invoices')->where('id', $invoiceId)->first())->toMatchObject(['amount_credited' => '6000.0000', 'balance_due' => '4000.0000', 'status' => 'issued']);
});

it('réalise une vente comptoir en une transaction, et n\'écrit rien si le paiement échoue (SAL-008)', function () {
    $ctx = salesSetup();
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);
    $body = fn (string $account) => ['lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 2]],
        'payment' => ['method' => 'cash', 'financial_account_id' => $account]];

    // Compte Wave pour un paiement en espèces : refusé (PAY-004), aucune trace.
    loginAs($seller)->postJson('/api/v1/counter-sales', $body(account($ctx['company'], 'wave')), salesKey())->assertStatus(422)->assertJsonPath('errors.0.pointer', '/financial_account_id');
    expect(DB::table('sales_orders')->where('company_id', $ctx['company']->id)->count())->toBe(0)
        ->and(position($ctx['wh'], $ctx['item']['variant'])['on_hand'])->toBe(100)
        ->and(DB::table('financial_movements')->where('company_id', $ctx['company']->id)->count())->toBe(0);

    $sale = loginAs($seller)->postJson('/api/v1/counter-sales', $body(account($ctx['company'], 'cash')), salesKey())->assertCreated()->json();
    expect($sale['data'])->toMatchArray(['status' => 'delivered', 'channel' => 'counter'])
        ->and($sale['data']['customer']['name'])->toBe('Client comptoir')
        ->and($sale['data']['invoice'])->toMatchArray(['payment_status' => 'paid', 'payment_method' => 'cash', 'balance_due' => '0.0000'])
        ->and($sale['meta']['payment']['number'])->toMatch('/^REC-\d{4}-\d{4}$/')
        ->and(position($ctx['wh'], $ctx['item']['variant'])['on_hand'])->toBe(98)
        ->and(DB::table('financial_accounts')->where('id', account($ctx['company'], 'cash'))->value('balance'))->toBe('2000.0000');
});

it('signale une marge négative sans bloquer (SAL-006)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 1, 'unit_price' => '500'],
    ]])->json('data');

    $response = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->assertOk();
    expect($response->json('data.status'))->toBe('confirmed')
        ->and($response->json('meta.warnings.0.code'))->toBe('negative_margin')
        ->and($response->json('data.lines.0.price_overridden'))->toBeTrue();
});

it('convertit un devis une seule fois en commande brouillon (QUO-002, QUO-003)', function () {
    $ctx = salesSetup();
    $quote = loginAs($ctx['admin'])->postJson('/api/v1/quotations', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 5, 'discount_type' => 'percent', 'discount_value' => '10'],
    ]])->assertCreated()->json('data');
    expect($quote['total'])->toBe('4500.0000')->and(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(0);

    loginAs($ctx['admin'])->postJson("/api/v1/quotations/{$quote['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent');
    $converted = loginAs($ctx['admin'])->postJson("/api/v1/quotations/{$quote['id']}/convert", [], salesKey())->assertCreated()->json('data');
    $order = loginAs($ctx['admin'])->getJson("/api/v1/sales-orders/{$converted['order_id']}")->json('data');

    expect($order)->toMatchArray(['status' => 'draft', 'total' => '4500.0000', 'quotation_id' => $quote['id']])
        ->and($converted['quotation']['status'])->toBe('converted');
    loginAs($ctx['admin'])->postJson("/api/v1/quotations/{$quote['id']}/convert", [], salesKey())->assertStatus(409)->assertJsonPath('code', 'already_processed');
});

it('ouvre et résout un litige indépendamment de l\'état (SAL-009)', function () {
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 1]]])->json('data');
    loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->assertOk();

    $dispute = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/disputes", ['reason' => 'Couleur différente'])->assertCreated()->json('data');
    expect(loginAs($ctx['admin'])->getJson("/api/v1/sales-orders/{$order['id']}")->json('data'))->toMatchArray(['has_dispute' => true, 'status' => 'confirmed']);
    loginAs($ctx['admin'])->postJson("/api/v1/disputes/{$dispute['id']}/resolve", ['resolution' => 'Échange accepté'])->assertOk();
    expect(loginAs($ctx['admin'])->getJson("/api/v1/sales-orders/{$order['id']}")->json('data.has_dispute'))->toBeFalse();
});
