<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RunsConcurrently;

/*
| L7-01 : campagne de concurrence sur les paiements et les approbations.
*/

it('n\'accepte jamais plus que le solde dû quand 8 règlements arrivent en même temps', function () {
    $ctx = invoicedOrder(10); // facture de 10 000
    $results = RunsConcurrently::run('pay-invoice', 8, [
        'company_id' => $ctx['company']->id, 'user_id' => $ctx['admin']->id, 'invoice_id' => $ctx['invoice'],
        'account_id' => account($ctx['company'], 'cash'), 'amount' => '3000',
    ]);

    // 3 × 3 000 passent (9 000), les 5 autres dépasseraient le solde de 1 000.
    expect(array_count_values($results))->toEqual(['paid' => 3, 'overpayment' => 5]);
    $invoice = DB::table('invoices')->where('id', $ctx['invoice'])->first();
    expect($invoice->amount_paid)->toBe('9000.0000')->and($invoice->balance_due)->toBe('1000.0000')
        ->and(DB::table('financial_accounts')->where('id', account($ctx['company'], 'cash'))->value('balance'))->toBe('9000.0000')
        ->and(DB::table('customer_payments')->where('invoice_id', $ctx['invoice'])->count())->toBe(3);
});

it('n\'exécute qu\'une fois une demande approuvée par 5 processus simultanés', function () {
    $ctx = salesSetup();
    loginAs($ctx['admin'])->postJson('/api/v1/approval-rules', ['name' => 'Toute remise', 'operation' => 'discount', 'threshold_type' => 'percent', 'threshold_value' => '0'])->assertCreated();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 3, 'discount_type' => 'percent', 'discount_value' => '5'],
    ]])->json('data');
    $requestId = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], ['Idempotency-Key' => Str::random(24)])->json('meta.approval_request_id');

    $results = RunsConcurrently::run('approve-request', 5, ['company_id' => $ctx['company']->id, 'user_id' => $ctx['admin']->id, 'request_id' => $requestId]);

    expect(array_count_values($results))->toEqual(['approved' => 1, 'already_processed' => 4])
        ->and(DB::table('invoices')->where('sales_order_id', $order['id'])->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $order['id'])->value('status'))->toBe('confirmed');
});
