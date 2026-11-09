<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\RunsConcurrently;

/*
| TEST-006 : 10 processus réservent 5 pièces sur un stock de 5 ⇒ exactement 1 succès, 9 insufficient_stock, réservé = 5.
*/

it('n\'accorde les dernières unités qu\'à un seul des 10 processus simultanés (TEST-006)', function () {
    $company = provisionedCompany();
    $user = userWithRole($company);
    $variant = createVariant($company);
    $warehouse = defaultWarehouse($company);
    receiveStock($user, $variant, 5);

    $results = RunsConcurrently::run('reserve-stock', 10, [
        'company_id' => $company->id, 'user_id' => $user->id, 'warehouse_id' => $warehouse, 'variant_id' => $variant, 'quantity' => 5,
    ]);

    expect(array_count_values($results))->toEqual(['reserved' => 1, 'insufficient_stock' => 9]);

    $position = DB::table('stock_positions')->where(['warehouse_id' => $warehouse, 'variant_id' => $variant])->first();
    expect((int) $position->qty_reserved)->toBe(5)
        ->and((int) $position->qty_available)->toBe(0)
        ->and(DB::table('stock_reservations')->count())->toBe(1);
});
