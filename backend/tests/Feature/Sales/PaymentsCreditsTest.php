<?php

declare(strict_types=1);

use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L4-08, L4-10 à L4-12, L5a-02 : règlements, verrou du moyen, contre-passation, avoirs, retours.
*/

it('encaisse une vente à crédit par Wave puis verrouille le moyen (TEST-011, TEST-012, PAY-001)', function () {
    $ctx = invoicedOrder(100);
    $url = "/api/v1/invoices/{$ctx['invoice']}/payments";

    $first = loginAs($ctx['admin'])->postJson($url, ['method' => 'wave', 'financial_account_id' => account($ctx['company'], 'wave'), 'amount' => '40000', 'external_reference' => 'WV-778'], salesKey())
        ->assertCreated()->json('data');
    expect($first)->toMatchArray(['method' => 'wave', 'amount' => '40000.0000', 'invoice_balance_due' => '60000.0000', 'invoice_payment_status' => 'partially_paid'])
        ->and($first['number'])->toMatch('/^REC-\d{4}-\d{4}$/')
        ->and(DB::table('financial_movements')->where('source_id', $first['id'])->value('movement_type'))->toBe('customer_receipt');

    loginAs($ctx['admin'])->postJson($url, ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash'), 'amount' => '10000'], salesKey())
        ->assertStatus(422)->assertJsonPath('code', 'payment_method_locked');
    loginAs($ctx['admin'])->postJson($url, ['method' => 'wave', 'financial_account_id' => account($ctx['company'], 'wave'), 'amount' => '60001'], salesKey())
        ->assertStatus(422)->assertJsonPath('code', 'overpayment');
    loginAs($ctx['admin'])->postJson($url, ['method' => 'wave', 'financial_account_id' => account($ctx['company'], 'wave'), 'amount' => '60000'], salesKey())
        ->assertCreated()->assertJsonPath('data.invoice_payment_status', 'paid');
});

it('contre-passe un règlement : solde rouvert, moyen libéré, mouvement inverse (PAY-006)', function () {
    $ctx = invoicedOrder(10);
    $payment = loginAs($ctx['admin'])->postJson("/api/v1/invoices/{$ctx['invoice']}/payments", ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash'), 'amount' => '10000'], salesKey())->json('data');
    $accountant = userWithRole($ctx['company'], RoleTemplate::Accountant);
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);

    loginAs($seller)->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'Erreur'], salesKey())->assertForbidden();
    $reversal = loginAs($accountant)->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'Billet refusé'], salesKey())->assertCreated()->json('data');

    expect($reversal)->toMatchArray(['amount' => '-10000.0000', 'reverses_payment_id' => $payment['id'], 'invoice_payment_status' => 'unpaid', 'invoice_balance_due' => '10000.0000'])
        ->and(DB::table('invoices')->where('id', $ctx['invoice'])->value('payment_method'))->toBeNull()
        ->and(DB::table('financial_accounts')->where('id', account($ctx['company'], 'cash'))->value('balance'))->toBe('0.0000')
        ->and(DB::table('customer_payments')->where('id', $payment['id'])->value('status'))->toBe('reversed');

    loginAs($accountant)->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'x'], salesKey())->assertStatus(409);
});

it('change le moyen de paiement par contre-passation puis nouvel encaissement (PAY-007)', function () {
    $ctx = invoicedOrder(10);
    loginAs($ctx['admin'])->postJson("/api/v1/invoices/{$ctx['invoice']}/payments", ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash'), 'amount' => '3000'], salesKey())->assertCreated();

    $new = loginAs($ctx['admin'])->postJson("/api/v1/invoices/{$ctx['invoice']}/change-payment-method", ['method' => 'orange_money', 'financial_account_id' => account($ctx['company'], 'orange_money'), 'reason' => 'Client a payé par OM'], salesKey())
        ->assertCreated()->json('data');

    expect($new)->toMatchArray(['method' => 'orange_money', 'amount' => '3000.0000', 'invoice_balance_due' => '7000.0000'])
        ->and(DB::table('invoices')->where('id', $ctx['invoice'])->value('payment_method'))->toBe('orange_money')
        ->and(DB::table('financial_accounts')->where('id', account($ctx['company'], 'cash'))->value('balance'))->toBe('0.0000');
});

it('refuse un solde de compte négatif (FIN-003)', function () {
    $ctx = invoicedOrder(1);
    $payment = loginAs($ctx['admin'])->postJson("/api/v1/invoices/{$ctx['invoice']}/payments", ['method' => 'cash', 'financial_account_id' => account($ctx['company'], 'cash'), 'amount' => '1000'], salesKey())->json('data');
    // La caisse est vidée par ailleurs : la contre-passation la rendrait négative.
    app('auth')->guard('web')->setUser($ctx['admin']);
    app(TenantContext::class)->runAs($ctx['company']->id, fn () => DB::transaction(fn () => app(FinancialLedger::class)
        ->post(account($ctx['company'], 'cash'), 'other_out', 'out', '1000', 'test', (string) Str::ulid(), 'Dépôt en banque', now())));

    loginAs($ctx['admin'])->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'x'], salesKey())->assertStatus(409)->assertJsonPath('code', 'insufficient_funds');
    expect(DB::table('customer_payments')->where('id', $payment['id'])->value('status'))->toBe('posted');
});

it('impute un avoir commercial : 20 000, imputation 8 000, disponible 12 000 (TEST-013, CRN-003)', function () {
    $ctx = invoicedOrder(30);
    $note = loginAs($ctx['admin'])->postJson('/api/v1/credit-notes', ['customer_id' => $ctx['customer']['id'], 'kind' => 'commercial', 'reason' => 'Geste commercial',
        'currency' => 'XOF', 'lines' => [['description' => 'Remise fidélité', 'amount' => '20000']]])->assertCreated()->json('data');
    expect($note)->toMatchArray(['status' => 'draft', 'number' => null]);

    loginAs($ctx['admin'])->postJson("/api/v1/credit-notes/{$note['id']}/apply", ['invoice_id' => $ctx['invoice'], 'amount' => '8000'], salesKey())->assertStatus(422)->assertJsonPath('code', 'credit_not_available');
    loginAs($ctx['admin'])->postJson("/api/v1/credit-notes/{$note['id']}/issue", [], salesKey())->assertOk()->assertJsonPath('data.status', 'issued');
    $applied = loginAs($ctx['admin'])->postJson("/api/v1/credit-notes/{$note['id']}/apply", ['invoice_id' => $ctx['invoice'], 'amount' => '8000'], salesKey())->assertOk()->json('data');

    expect($applied)->toMatchArray(['amount_applied' => '8000.0000', 'amount_available' => '12000.0000'])
        ->and(DB::table('invoices')->where('id', $ctx['invoice'])->first())->toMatchObject(['amount_credited' => '8000.0000', 'balance_due' => '22000.0000', 'payment_method' => null]);
    loginAs($ctx['admin'])->postJson("/api/v1/credit-notes/{$note['id']}/apply", ['invoice_id' => $ctx['invoice'], 'amount' => '12001'], salesKey())->assertStatus(422);
});

it('traite un retour : lot d\'origine, contrôle, endommagé bloqué, avoir de retour (RET-001 à RET-005, TEST-014)', function () {
    $ctx = invoicedOrder(10, deliver: true);
    $lineId = $ctx['order']['lines'][0]['id'];

    loginAs($ctx['admin'])->postJson('/api/v1/returns', ['sales_order_id' => $ctx['order']['id'], 'return_type' => 'defective', 'lines' => [$lineId => 11]])->assertStatus(422);
    $return = loginAs($ctx['admin'])->postJson('/api/v1/returns', ['sales_order_id' => $ctx['order']['id'], 'return_type' => 'defective', 'lines' => [$lineId => 3]])->assertCreated()->json('data');
    expect($return['lines'][0]['lot_id'])->toBe($ctx['item']['lot']);

    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/receive", [], salesKey())->assertOk()->assertJsonPath('data.status', 'received');
    expect(position($ctx['wh'], $ctx['item']['variant']))->toMatchArray(['on_hand' => 93, 'blocked' => 3, 'available' => 90])
        ->and(DB::table('stock_movements')->where('source_id', $return['id'])->first())->toMatchObject(['movement_type' => 'return_in', 'quantity' => 3, 'unit_cost' => '600.00000000']);

    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/inspect", ['decisions' => []], salesKey())->assertStatus(422);
    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/inspect", ['decisions' => [$return['lines'][0]['id'] => 'damaged']], salesKey())->assertOk();
    expect(position($ctx['wh'], $ctx['item']['variant'])['blocked'])->toBe(3)
        ->and(DB::table('stock_holds')->where('lot_id', $ctx['item']['lot'])->where('status', 'active')->value('reason'))->toBe('damaged');

    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/close", [], salesKey())->assertOk()->assertJsonPath('data.status', 'closed');
    expect(DB::table('credit_notes')->where('return_id', $return['id'])->first())->toMatchObject(['kind' => 'return', 'total' => '3000.0000', 'amount_applied' => '3000.0000'])
        ->and(DB::table('sales_order_lines')->where('id', $lineId)->value('qty_returned'))->toBe(3);
});

it('produit les messages WhatsApp avec un lien signé, et refuse un lien falsifié (L4-02, L4-15)', function () {
    $ctx = invoicedOrder(2);
    $message = loginAs($ctx['admin'])->getJson("/api/v1/invoices/{$ctx['invoice']}/whatsapp-message")->assertOk()->json('data');

    expect($message['url'])->toStartWith('https://wa.me/221771234567?text=')
        ->and($message['message'])->toContain("2\u{202F}000");

    $reminder = loginAs($ctx['admin'])->getJson("/api/v1/receivables/{$ctx['invoice']}/reminder")->assertOk()->json('data.message');
    expect($reminder)->toContain("solde de 2\u{202F}000");

    auth('web')->logout();
    $this->get(str_replace('signature=', 'signature=x', $message['share_link']))->assertNotFound();
});
