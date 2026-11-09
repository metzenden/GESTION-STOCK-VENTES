<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L5-01 à L5-05 : transferts, dépenses, corrections, ouverture, indicateurs (docs/04 §11).
*/

function finKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

function balanceOf(string $accountId): string
{
    return (string) DB::table('financial_accounts')->where('id', $accountId)->value('balance');
}

it('comptabilise l\'ouverture une seule fois : soldes et stock initial (FIN-007)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);
    $cash = account($company, 'cash');
    $wh = defaultWarehouse($company);

    loginAs($admin)->putJson('/api/v1/opening', ['start_date' => '2026-11-01', 'accounts' => [['account_id' => $cash, 'amount' => '150000']],
        'stock' => [['warehouse_id' => $wh, 'variant_id' => $variant, 'quantity' => 40, 'unit_cost' => '750']]])->assertOk()->assertJsonPath('data.status', 'draft');
    expect(balanceOf($cash))->toBe('0.0000');

    loginAs($admin)->postJson('/api/v1/opening/post', [], finKey())->assertOk()->assertJsonPath('data.status', 'posted');
    expect(balanceOf($cash))->toBe('150000.0000')
        ->and(position($wh, $variant))->toMatchArray(['on_hand' => 40])
        ->and(DB::table('stock_lots')->where('variant_id', $variant)->value('origin_type'))->toBe('opening')
        ->and(DB::table('sales_orders')->where('company_id', $company->id)->count())->toBe(0);

    loginAs($admin)->postJson('/api/v1/opening/post', [], finKey())->assertStatus(409);
    loginAs($admin)->putJson('/api/v1/opening', ['start_date' => '2026-11-02'])->assertStatus(409);
});

it('transfère entre comptes, y compris en devises avec taux obligatoire (FIN-004)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    [$cash, $bank] = [account($company, 'cash'), account($company, 'bank')];
    loginAs($admin)->putJson('/api/v1/opening', ['start_date' => '2026-11-01', 'accounts' => [['account_id' => $cash, 'amount' => '100000']]])->assertOk();
    loginAs($admin)->postJson('/api/v1/opening/post', [], finKey())->assertOk();

    loginAs($admin)->postJson('/api/v1/account-transfers', ['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '60000'], finKey())
        ->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'VIR-'));
    expect(balanceOf($cash))->toBe('40000.0000')->and(balanceOf($bank))->toBe('60000.0000');

    loginAs($admin)->postJson('/api/v1/account-transfers', ['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '50000'], finKey())
        ->assertStatus(409)->assertJsonPath('code', 'insufficient_funds');

    $eur = loginAs($admin)->postJson('/api/v1/financial-accounts', ['code' => 'BQ-EUR', 'name' => 'Banque EUR', 'account_type' => 'bank', 'currency' => 'EUR'])->json('data.id');
    loginAs($admin)->postJson('/api/v1/account-transfers', ['from_account_id' => $bank, 'to_account_id' => $eur, 'amount' => '6559.57'], finKey())->assertStatus(422);
    loginAs($admin)->postJson('/api/v1/account-transfers', ['from_account_id' => $bank, 'to_account_id' => $eur, 'amount' => '6559.57', 'exchange_rate' => '0.0015244'], finKey())
        ->assertCreated()->assertJsonPath('data.amount_to', '9.9994'); // 6 559,57 × 0,0015244 = 9,99940… (HALF_UP)
});

it('enregistre une dépense puis la contre-passe (FIN-005)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $cash = account($company, 'cash');
    $category = DB::table('expense_categories')->where('company_id', $company->id)->where('name', 'Loyer')->value('id');
    loginAs($admin)->putJson('/api/v1/opening', ['start_date' => '2026-11-01', 'accounts' => [['account_id' => $cash, 'amount' => '200000']]])->assertOk();
    loginAs($admin)->postJson('/api/v1/opening/post', [], finKey())->assertOk();

    $expense = loginAs($admin)->postJson('/api/v1/expenses', ['category_id' => $category, 'financial_account_id' => $cash, 'expense_date' => '2026-11-05', 'label' => 'Loyer novembre', 'amount' => '150000'], finKey())
        ->assertCreated()->json('data');
    expect(balanceOf($cash))->toBe('50000.0000')->and($expense['number'])->toStartWith('DEP-');

    loginAs($admin)->postJson("/api/v1/expenses/{$expense['id']}/reverse", ['reason' => 'Doublon'], finKey())->assertOk()->assertJsonPath('data.status', 'reversed');
    expect(balanceOf($cash))->toBe('200000.0000');
});

it('passe une correction financière sans approbation requise, avec mouvement correctif', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $cash = account($company, 'cash');

    loginAs($admin)->postJson('/api/v1/financial-corrections', ['financial_account_id' => $cash, 'direction' => 'in', 'amount' => '2500', 'reason' => 'Écart de caisse'], finKey())
        ->assertCreated()->assertJsonPath('data.status', 'posted');
    expect(balanceOf($cash))->toBe('2500.0000')
        ->and(DB::table('financial_movements')->where('financial_account_id', $cash)->value('movement_type'))->toBe('correction');
});

it('calcule les indicateurs d\'un jeu de référence au franc près (L5-05, docs/04 §11.1)', function () {
    // Jeu calculé à la main : 10 pièces vendues 1 000 (coût 600), 2 retournées, une dépense de 500.
    $ctx = invoicedOrder(10, deliver: true);
    $lineId = $ctx['order']['lines'][0]['id'];
    $return = loginAs($ctx['admin'])->postJson('/api/v1/returns', ['sales_order_id' => $ctx['order']['id'], 'return_type' => 'defective', 'lines' => [$lineId => 2]])->json('data');
    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/receive", [], finKey())->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/inspect", ['decisions' => [$return['lines'][0]['id'] => 'restock']], finKey())->assertOk();
    loginAs($ctx['admin'])->postJson("/api/v1/returns/{$return['id']}/close", [], finKey())->assertOk();
    $cash = account($ctx['company'], 'cash');
    loginAs($ctx['admin'])->postJson("/api/v1/invoices/{$ctx['invoice']}/payments", ['method' => 'cash', 'financial_account_id' => $cash, 'amount' => '3000'], finKey())->assertCreated();
    $category = DB::table('expense_categories')->where('company_id', $ctx['company']->id)->where('name', 'Transport')->value('id');
    loginAs($ctx['admin'])->postJson('/api/v1/expenses', ['category_id' => $category, 'financial_account_id' => $cash, 'expense_date' => CarbonImmutable::now()->toDateString(), 'label' => 'Taxi', 'amount' => '500'], finKey())->assertCreated();

    $today = CarbonImmutable::now()->toDateString();
    $kpi = loginAs($ctx['admin'])->getJson("/api/v1/reports/indicators?from={$today}&to={$today}")->assertOk()->json('data');

    expect($kpi)->toMatchArray([
        'invoiced' => '8000.0000',          // 10 000 facturés − 2 000 d'avoir de retour
        'net_revenue' => '8000.0000',       // 10 000 remis − 2 000 retournés
        'cost_of_goods_sold' => '4800.0000', // 6 000 − 1 200
        'gross_margin' => '3200.0000',
        'charges' => '500.0000',
        'net_result' => '2700.0000',
        'collected' => '3000.0000',
        'receivables' => '5000.0000',       // 10 000 − 2 000 imputés − 3 000 réglés
    ]);
});
