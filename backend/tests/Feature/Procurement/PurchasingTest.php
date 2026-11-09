<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L3-01 à L3-03 : fournisseurs, commandes d'achat, engagements (PUR-001 à PUR-005, GEN-005).
*/

function buyerSetup(string $currency = 'USD'): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);
    $supplier = loginAs($admin)->postJson('/api/v1/suppliers', ['code' => 'F-'.Str::random(4), 'name' => 'Usine de Canton', 'currency' => $currency])
        ->assertCreated()->json('data.id');

    return [$company, $admin, $variant, $supplier];
}

function draftOrder($admin, string $supplier, string $variant, int $qty = 10, string $price = '12.5'): array
{
    return loginAs($admin)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier, 'estimated_rate' => '600',
        'lines' => [['variant_id' => $variant, 'qty_units' => $qty, 'unit_price' => $price]],
    ])->assertCreated()->json('data');
}

function idem(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

it('confirme une commande : conditionnement figé et engagement du montant total en devise (PUR-001)', function () {
    [, $admin, $variant, $supplier] = buyerSetup();
    $order = draftOrder($admin, $supplier, $variant, 40, '2.255');

    expect($order['total_amount'])->toBe('90.2000')->and($order['currency'])->toBe('USD')->and($order['lines'][0]['qty_base'])->toBe(40);

    loginAs($admin)->postJson("/api/v1/purchase-orders/{$order['id']}/confirm", [], idem())->assertOk()->assertJsonPath('data.status', 'confirmed');

    $statement = loginAs($admin)->getJson("/api/v1/suppliers/{$supplier}/statement")->assertOk()->json('data');
    expect($statement['balances'])->toBe([['currency' => 'USD', 'committed' => '90.2000', 'paid' => '0.0000', 'balance' => '90.2000']])
        ->and($statement['bills'][0]['source_type'])->toBe('purchase_order')
        ->and($statement['supplier']['has_history'])->toBeTrue()
        ->and(DB::table('variant_units')->where('variant_id', $variant)->where('factor', 1)->value('locked_at'))->not->toBeNull();

    // Une commande confirmée ne se modifie plus.
    loginAs($admin)->patchJson("/api/v1/purchase-orders/{$order['id']}", ['notes' => 'x'])->assertStatus(409);
});

it('annule une commande confirmée sans réception : l\'engagement est compensé (PUR-004)', function () {
    [, $admin, $variant, $supplier] = buyerSetup();
    $order = draftOrder($admin, $supplier, $variant);
    loginAs($admin)->postJson("/api/v1/purchase-orders/{$order['id']}/confirm", [], idem())->assertOk();

    loginAs($admin)->postJson("/api/v1/purchase-orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $statement = loginAs($admin)->getJson("/api/v1/suppliers/{$supplier}/statement")->json('data');
    expect($statement['balances'][0]['balance'])->toBe('0.0000')
        ->and(array_column($statement['bills'], 'amount'))->toBe(['125.0000', '-125.0000'])
        ->and(array_unique(array_column($statement['bills'], 'status')))->toBe(['cancelled']);

    loginAs($admin)->postJson("/api/v1/purchase-orders/{$order['id']}/cancel")->assertStatus(409);
});

it('tient la dette par devise et accepte un avoir fournisseur manuel (PUR-002)', function () {
    [, $admin, , $supplier] = buyerSetup('EUR');

    loginAs($admin)->postJson('/api/v1/supplier-bills', ['supplier_id' => $supplier, 'amount' => '1500.50', 'bill_date' => '2026-11-01'], idem())
        ->assertCreated()->assertJsonPath('data.source_type', 'manual')->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'ENG-2026-'));
    loginAs($admin)->postJson('/api/v1/supplier-bills', ['supplier_id' => $supplier, 'amount' => '-200', 'bill_date' => '2026-11-02'], idem())
        ->assertCreated()->assertJsonPath('data.source_type', 'supplier_credit');
    loginAs($admin)->postJson('/api/v1/supplier-bills', ['supplier_id' => $supplier, 'amount' => '0', 'bill_date' => '2026-11-02'], idem())->assertStatus(422);

    expect(loginAs($admin)->getJson("/api/v1/suppliers/{$supplier}/statement")->json('data.balances'))
        ->toBe([['currency' => 'EUR', 'committed' => '1300.5000', 'paid' => '0.0000', 'balance' => '1300.5000']]);

    // La devise d'un fournisseur ayant des engagements est figée.
    loginAs($admin)->patchJson("/api/v1/suppliers/{$supplier}", ['currency' => 'USD'])->assertStatus(422)->assertJsonPath('errors.0.pointer', '/currency');
});

it('réserve les dettes et engagements aux rôles financiers (GEN-005, D-088)', function () {
    [$company, , , $supplier] = buyerSetup();
    $storekeeper = userWithRole($company, RoleTemplate::Storekeeper);
    $seller = userWithRole($company, RoleTemplate::Seller);

    loginAs($storekeeper)->getJson('/api/v1/suppliers')->assertOk();
    loginAs($storekeeper)->getJson("/api/v1/suppliers/{$supplier}/statement")->assertForbidden();
    loginAs($storekeeper)->getJson('/api/v1/supplier-bills')->assertForbidden();
    loginAs($seller)->getJson('/api/v1/suppliers')->assertForbidden();
    loginAs($seller)->getJson('/api/v1/purchase-orders')->assertForbidden();
});

it('valide les lignes de commande : variante, conditionnement, prix', function () {
    [$company, $admin, $variant, $supplier] = buyerSetup();
    $other = createVariant($company);
    $foreignUnit = DB::table('variant_units')->where('variant_id', $other)->value('id');

    loginAs($admin)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'lines' => [['variant_id' => $variant, 'unit_id' => $foreignUnit, 'qty_units' => 1, 'unit_price' => '1']]])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/lines/0/unit_id');
    loginAs($admin)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'lines' => [['variant_id' => (string) Str::ulid(), 'qty_units' => 1, 'unit_price' => '1']]])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/lines/0/variant_id');

    $carton = DB::table('variant_units')->where('variant_id', $variant)->where('code', 'carton')->first();

    if ($carton !== null) {
        $order = loginAs($admin)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'lines' => [['variant_id' => $variant, 'unit_id' => $carton->id, 'qty_units' => 3, 'unit_price' => '24']]])
            ->assertCreated()->json('data');
        expect($order['lines'][0]['qty_base'])->toBe(3 * (int) $carton->factor)->and($order['total_amount'])->toBe('72.0000');
    }
});
