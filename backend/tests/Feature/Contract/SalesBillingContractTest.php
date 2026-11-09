<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

/*
| DoD §3 : les opérations du lot 4 respectent le contrat openapi/openapi.yaml.
*/

function c4(TestResponse $response, string $method, string $path, int $status): TestResponse
{
    $response->assertStatus($status);

    if ($response->headers->get('Content-Type') !== 'application/pdf') {
        OpenApiContract::assertResponseMatches($response, $method, $path);
    }

    return $response;
}

it('respecte le contrat : clients, devis, commandes, livraisons, retours, factures, avoirs, paiements, comptes', function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7 factice', 200, ['Content-Type' => 'application/pdf'])]);
    $ctx = salesSetup();
    $s = fn () => loginAs($ctx['admin']);
    $v = $ctx['item']['variant'];
    $cust = $ctx['customer']['id'];

    c4($s()->getJson('/api/v1/customers?q=Awa'), 'GET', '/customers', 200);
    c4($s()->getJson("/api/v1/customers/{$cust}"), 'GET', '/customers/{customer}', 200);
    c4($s()->patchJson("/api/v1/customers/{$cust}", ['address' => 'Médina']), 'PATCH', '/customers/{customer}', 200);
    c4($s()->postJson('/api/v1/customers', ['name' => 'Ets Ndiaye', 'customer_type' => 'business']), 'POST', '/customers', 201);

    $quote = c4($s()->postJson('/api/v1/quotations', ['customer_id' => $cust, 'lines' => [['variant_id' => $v, 'qty_units' => 2]]]), 'POST', '/quotations', 201)->json('data.id');
    c4($s()->patchJson("/api/v1/quotations/{$quote}", ['notes' => 'Prix valables 30 jours']), 'PATCH', '/quotations/{quotation}', 200);
    c4($s()->getJson('/api/v1/quotations'), 'GET', '/quotations', 200);
    c4($s()->getJson("/api/v1/quotations/{$quote}"), 'GET', '/quotations/{quotation}', 200);
    c4($s()->postJson("/api/v1/quotations/{$quote}/send"), 'POST', '/quotations/{quotation}/send', 200);
    c4($s()->postJson("/api/v1/quotations/{$quote}/accept"), 'POST', '/quotations/{quotation}/accept', 200);
    c4($s()->postJson("/api/v1/quotations/{$quote}/convert", [], salesKey()), 'POST', '/quotations/{quotation}/convert', 201);
    $other = $s()->postJson('/api/v1/quotations', ['customer_id' => $cust, 'lines' => [['variant_id' => $v, 'qty_units' => 1]]])->json('data.id');
    c4($s()->postJson("/api/v1/quotations/{$other}/cancel"), 'POST', '/quotations/{quotation}/cancel', 200);

    $order = c4($s()->postJson('/api/v1/sales-orders', ['customer_id' => $cust, 'fulfilment_mode' => 'delivery', 'lines' => [['variant_id' => $v, 'qty_units' => 4]]]), 'POST', '/sales-orders', 201)->json('data');
    c4($s()->patchJson("/api/v1/sales-orders/{$order['id']}", ['delivery_address' => 'Plateau', 'version' => 1]), 'PATCH', '/sales-orders/{order}', 200);
    $confirmed = c4($s()->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey()), 'POST', '/sales-orders/{order}/submit', 200)->json('data');
    c4($s()->getJson('/api/v1/sales-orders'), 'GET', '/sales-orders', 200);
    c4($s()->getJson("/api/v1/sales-orders/{$order['id']}"), 'GET', '/sales-orders/{order}', 200);
    $dispute = c4($s()->postJson("/api/v1/sales-orders/{$order['id']}/disputes", ['reason' => 'Retard']), 'POST', '/sales-orders/{order}/disputes', 201)->json('data.id');
    c4($s()->postJson("/api/v1/disputes/{$dispute}/resolve", ['resolution' => 'Livré']), 'POST', '/disputes/{dispute}/resolve', 200);

    $d = c4($s()->postJson("/api/v1/sales-orders/{$order['id']}/deliveries", ['lines' => [$confirmed['lines'][0]['id'] => 3]]), 'POST', '/sales-orders/{order}/deliveries', 201)->json('data.id');
    c4($s()->postJson("/api/v1/deliveries/{$d}/ready"), 'POST', '/deliveries/{delivery}/ready', 200);
    c4($s()->postJson("/api/v1/deliveries/{$d}/ship", [], salesKey()), 'POST', '/deliveries/{delivery}/ship', 200);
    c4($s()->postJson("/api/v1/deliveries/{$d}/fail", ['reason' => 'Absent'], salesKey()), 'POST', '/deliveries/{delivery}/fail', 200);
    c4($s()->postJson("/api/v1/deliveries/{$d}/return-to-warehouse", [], salesKey()), 'POST', '/deliveries/{delivery}/return-to-warehouse', 200);
    c4($s()->postJson("/api/v1/deliveries/{$d}/ship", [], salesKey()), 'POST', '/deliveries/{delivery}/ship', 200);
    c4($s()->postJson("/api/v1/deliveries/{$d}/deliver", [], salesKey()), 'POST', '/deliveries/{delivery}/deliver', 200);
    c4($s()->getJson('/api/v1/deliveries'), 'GET', '/deliveries', 200);
    c4($s()->getJson("/api/v1/deliveries/{$d}"), 'GET', '/deliveries/{delivery}', 200);
    c4($s()->getJson("/api/v1/sales-orders/{$order['id']}/deliveries"), 'GET', '/sales-orders/{order}/deliveries', 200);
    $d2 = $s()->postJson("/api/v1/sales-orders/{$order['id']}/deliveries")->json('data.id');
    c4($s()->postJson("/api/v1/deliveries/{$d2}/cancel"), 'POST', '/deliveries/{delivery}/cancel', 200);
    c4($s()->postJson("/api/v1/sales-orders/{$order['id']}/cancel-remaining", ['reason' => 'Rupture'], salesKey()), 'POST', '/sales-orders/{order}/cancel-remaining', 200);

    $inv = $confirmed['invoice_id'];
    c4($s()->getJson('/api/v1/invoices'), 'GET', '/invoices', 200);
    c4($s()->getJson("/api/v1/invoices/{$inv}"), 'GET', '/invoices/{invoice}', 200);
    c4($s()->getJson("/api/v1/invoices/{$inv}/pdf"), 'GET', '/invoices/{invoice}/pdf', 200);
    c4($s()->getJson("/api/v1/invoices/{$inv}/share-link"), 'GET', '/invoices/{invoice}/share-link', 200);
    c4($s()->getJson("/api/v1/invoices/{$inv}/whatsapp-message"), 'GET', '/invoices/{invoice}/whatsapp-message', 200);
    $pay = c4($s()->postJson("/api/v1/invoices/{$inv}/payments", ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash'), 'amount' => '1000'], salesKey()), 'POST', '/invoices/{invoice}/payments', 201)->json('data.id');
    c4($s()->getJson("/api/v1/invoices/{$inv}/payments"), 'GET', '/invoices/{invoice}/payments', 200);
    c4($s()->getJson('/api/v1/payments'), 'GET', '/payments', 200);
    c4($s()->getJson("/api/v1/payments/{$pay}"), 'GET', '/payments/{payment}', 200);
    c4($s()->getJson("/api/v1/payments/{$pay}/receipt.pdf"), 'GET', '/payments/{payment}/receipt.pdf', 200);
    c4($s()->postJson("/api/v1/invoices/{$inv}/change-payment-method", ['method' => 'wave', 'financial_account_id' => account($ctx['company'], 'wave'), 'reason' => 'Erreur'], salesKey()), 'POST', '/invoices/{invoice}/change-payment-method', 201);
    $active = collect($s()->getJson("/api/v1/invoices/{$inv}/payments")->json('data'))->first(fn ($p) => $p['status'] === 'posted' && ! str_starts_with($p['amount'], '-'));
    c4($s()->postJson("/api/v1/payments/{$active['id']}/reverse", ['reason' => 'Test'], salesKey()), 'POST', '/payments/{payment}/reverse', 201);
    c4($s()->getJson('/api/v1/receivables'), 'GET', '/receivables', 200);
    c4($s()->getJson("/api/v1/receivables/{$inv}/reminder"), 'GET', '/receivables/{invoice}/reminder', 200);
    c4($s()->getJson("/api/v1/customers/{$cust}/statement"), 'GET', '/customers/{customer}/statement', 200);

    $ret = c4($s()->postJson('/api/v1/returns', ['sales_order_id' => $order['id'], 'return_type' => 'defective', 'lines' => [$confirmed['lines'][0]['id'] => 1]]), 'POST', '/returns', 201)->json('data');
    c4($s()->postJson("/api/v1/returns/{$ret['id']}/receive", [], salesKey()), 'POST', '/returns/{return}/receive', 200);
    c4($s()->postJson("/api/v1/returns/{$ret['id']}/inspect", ['decisions' => [$ret['lines'][0]['id'] => 'restock']], salesKey()), 'POST', '/returns/{return}/inspect', 200);
    c4($s()->postJson("/api/v1/returns/{$ret['id']}/close", [], salesKey()), 'POST', '/returns/{return}/close', 200);
    c4($s()->getJson('/api/v1/returns'), 'GET', '/returns', 200);
    c4($s()->getJson("/api/v1/returns/{$ret['id']}"), 'GET', '/returns/{return}', 200);
    $ret2 = $s()->postJson('/api/v1/returns', ['sales_order_id' => $order['id'], 'return_type' => 'other', 'lines' => [$confirmed['lines'][0]['id'] => 1]])->json('data.id');
    c4($s()->postJson("/api/v1/returns/{$ret2}/cancel"), 'POST', '/returns/{return}/cancel', 200);

    $note = c4($s()->postJson('/api/v1/credit-notes', ['customer_id' => $cust, 'kind' => 'commercial', 'reason' => 'Geste', 'currency' => 'XOF', 'lines' => [['description' => 'Geste', 'amount' => '100']]]), 'POST', '/credit-notes', 201)->json('data.id');
    c4($s()->postJson("/api/v1/credit-notes/{$note}/issue", [], salesKey()), 'POST', '/credit-notes/{note}/issue', 200);
    c4($s()->postJson("/api/v1/credit-notes/{$note}/apply", ['invoice_id' => $inv, 'amount' => '100'], salesKey()), 'POST', '/credit-notes/{note}/apply', 200);
    c4($s()->getJson('/api/v1/credit-notes'), 'GET', '/credit-notes', 200);
    c4($s()->getJson("/api/v1/credit-notes/{$note}"), 'GET', '/credit-notes/{note}', 200);
    c4($s()->getJson("/api/v1/credit-notes/{$note}/pdf"), 'GET', '/credit-notes/{note}/pdf', 200);
    c4($s()->getJson("/api/v1/customers/{$cust}/credits"), 'GET', '/customers/{customer}/credits', 200);

    c4($s()->postJson('/api/v1/counter-sales', ['lines' => [['variant_id' => $v, 'qty_units' => 1]], 'payment' => ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash')]], salesKey()), 'POST', '/counter-sales', 201);
    $sale = $s()->postJson('/api/v1/sales-orders', ['customer_id' => $cust, 'lines' => [['variant_id' => $v, 'qty_units' => 1]]])->json('data.id');
    $s()->postJson("/api/v1/sales-orders/{$sale}/submit", [], salesKey())->assertOk();
    c4($s()->postJson("/api/v1/sales-orders/{$sale}/cancel", ['reason' => 'Annulé'], salesKey()), 'POST', '/sales-orders/{order}/cancel', 200);

    $acc = c4($s()->getJson('/api/v1/financial-accounts'), 'GET', '/financial-accounts', 200)->json('data.0.id');
    c4($s()->postJson('/api/v1/financial-accounts', ['code' => 'BANQUE2', 'name' => 'Banque 2', 'account_type' => 'bank', 'currency' => 'XOF']), 'POST', '/financial-accounts', 201);
    c4($s()->patchJson("/api/v1/financial-accounts/{$acc}", ['name' => 'Caisse principale']), 'PATCH', '/financial-accounts/{account}', 200);
    c4($s()->getJson("/api/v1/financial-accounts/{$acc}/movements"), 'GET', '/financial-accounts/{account}/movements', 200);
});
