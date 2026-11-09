<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

/*
| DoD §3 : les opérations du lot 2 respectent le contrat openapi/openapi.yaml.
*/

function c2(TestResponse $response, string $method, string $path, int $status): TestResponse
{
    $response->assertStatus($status);
    OpenApiContract::assertResponseMatches($response, $method, $path);

    return $response;
}

it('respecte le contrat : catalogue', function () {
    Storage::fake('private');
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $s = fn () => loginAs($admin);

    $category = c2($s()->postJson('/api/v1/categories', ['name' => 'Textile']), 'POST', '/categories', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/categories/{$category}", ['is_active' => true]), 'PATCH', '/categories/{id}', 200);
    c2($s()->getJson('/api/v1/categories'), 'GET', '/categories', 200);
    $attribute = c2($s()->postJson('/api/v1/attributes', ['name' => 'Taille']), 'POST', '/attributes', 201)->json('data.id');
    c2($s()->postJson("/api/v1/attributes/{$attribute}/values", ['value' => 'M']), 'POST', '/attributes/{id}/values', 201);
    c2($s()->getJson('/api/v1/attributes'), 'GET', '/attributes', 200);

    $product = c2($s()->postJson('/api/v1/products', ['reference' => 'CT-1', 'name' => 'Chemise', 'category_id' => $category, 'pieces_per_carton' => 12, 'barcode' => '7770001']), 'POST', '/products', 201)->json('data');
    $id = $product['id'];
    $variant = $product['variants'][0]['id'];
    $piece = $product['variants'][0]['units'][0]['id'];
    c2($s()->getJson('/api/v1/products'), 'GET', '/products', 200);
    c2($s()->getJson("/api/v1/products/{$id}"), 'GET', '/products/{id}', 200);
    c2($s()->patchJson("/api/v1/products/{$id}", ['name' => 'Chemise lin'], ['If-Match' => '"1"']), 'PATCH', '/products/{id}', 200);
    c2($s()->patchJson("/api/v1/products/{$id}", ['name' => 'Conflit'], ['If-Match' => '"1"']), 'PATCH', '/products/{id}', 412);
    c2($s()->postJson("/api/v1/products/{$id}/deactivate"), 'POST', '/products/{id}/deactivate', 200);
    c2($s()->postJson("/api/v1/products/{$id}/activate"), 'POST', '/products/{id}/activate', 200);
    c2($s()->getJson("/api/v1/products/{$id}/variants"), 'GET', '/products/{id}/variants', 200);
    $second = c2($s()->postJson("/api/v1/products/{$id}/variants", ['sku' => 'CT-1-L', 'label' => 'L']), 'POST', '/products/{id}/variants', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/product-variants/{$second}", ['low_stock_threshold' => 5]), 'PATCH', '/product-variants/{id}', 200);
    $unit = c2($s()->postJson("/api/v1/product-variants/{$variant}/units", ['code' => 'paquet', 'label' => 'paquet', 'factor' => 3]), 'POST', '/product-variants/{id}/units', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/variant-units/{$unit}", ['barcode' => '7770002']), 'PATCH', '/variant-units/{id}', 200);
    c2($s()->getJson("/api/v1/product-variants/{$variant}/units"), 'GET', '/product-variants/{id}/units', 200);
    c2($s()->getJson('/api/v1/product-variants/lookup?barcode=7770002'), 'GET', '/product-variants/lookup', 200);

    $lists = c2($s()->getJson('/api/v1/price-lists'), 'GET', '/price-lists', 200)->json('data');
    $vip = c2($s()->postJson('/api/v1/price-lists', ['code' => 'vip', 'name' => 'VIP', 'currency' => 'XOF']), 'POST', '/price-lists', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/price-lists/{$vip}", ['name' => 'Clients VIP']), 'PATCH', '/price-lists/{id}', 200);
    c2($s()->postJson("/api/v1/product-variants/{$variant}/prices", ['price_list_id' => $lists[0]['id'], 'unit_id' => $piece, 'price' => '2500', 'valid_from' => '2026-01-01']), 'POST', '/product-variants/{id}/prices', 201);
    c2($s()->getJson("/api/v1/product-variants/{$variant}/prices"), 'GET', '/product-variants/{id}/prices', 200);
    c2($s()->getJson("/api/v1/pricing/quote?variant_id={$variant}&unit_id={$unit}"), 'GET', '/pricing/quote', 200);

    $image = c2($s()->post("/api/v1/products/{$id}/images", ['image' => UploadedFile::fake()->image('c.png', 400, 400)], ['Accept' => 'application/json']), 'POST', '/products/{id}/images', 201)->json('data.id');
    c2($s()->deleteJson("/api/v1/product-images/{$image}"), 'DELETE', '/product-images/{id}', 204);

    $free = $s()->postJson('/api/v1/products', ['reference' => 'CT-2', 'name' => 'Libre'])->json('data.id');
    c2($s()->deleteJson("/api/v1/products/{$free}"), 'DELETE', '/products/{id}', 204);
    c2($s()->postJson("/api/v1/products/{$id}/archive"), 'POST', '/products/{id}/archive', 200);
    c2($s()->postJson('/api/v1/products', ['reference' => 'CT-1', 'name' => 'Doublon']), 'POST', '/products', 422);
});

it('respecte le contrat : stocks', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $variant = createVariant($company);
    $main = defaultWarehouse($company);
    $lot = receiveStock($admin, $variant, 30, '500');
    $s = fn () => loginAs($admin);
    $k = fn () => ['Idempotency-Key' => Str::random(24)];

    c2($s()->getJson('/api/v1/warehouses'), 'GET', '/warehouses', 200);
    $other = c2($s()->postJson('/api/v1/warehouses', ['code' => 'SUD', 'name' => 'Dépôt Sud']), 'POST', '/warehouses', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/warehouses/{$other}", ['address' => 'Mbour']), 'PATCH', '/warehouses/{id}', 200);
    c2($s()->getJson('/api/v1/stock/positions'), 'GET', '/stock/positions', 200);
    c2($s()->getJson('/api/v1/stock/lots'), 'GET', '/stock/lots', 200);
    c2($s()->getJson('/api/v1/stock/movements'), 'GET', '/stock/movements', 200);
    c2($s()->getJson('/api/v1/stock/valuation'), 'GET', '/stock/valuation', 200);
    c2(loginAs(userWithRole($company, RoleTemplate::Storekeeper))->getJson('/api/v1/stock/lots'), 'GET', '/stock/lots', 200);

    $adj = c2($s()->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $main, 'lines' => [['variant_id' => $variant, 'lot_id' => $lot, 'quantity' => -1]]]), 'POST', '/stock-adjustments', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/stock-adjustments/{$adj}", ['reason' => 'Casse']), 'PATCH', '/stock-adjustments/{id}', 200);
    c2($s()->getJson("/api/v1/stock-adjustments/{$adj}"), 'GET', '/stock-adjustments/{id}', 200);
    c2($s()->postJson("/api/v1/stock-adjustments/{$adj}/submit", [], $k()), 'POST', '/stock-adjustments/{id}/submit', 200);
    c2($s()->postJson("/api/v1/stock-adjustments/{$adj}/cancel"), 'POST', '/stock-adjustments/{id}/cancel', 409);
    $draft = $s()->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $main, 'lines' => [['variant_id' => $variant, 'quantity' => 2, 'unit_cost' => '400']]])->json('data.id');
    c2($s()->postJson("/api/v1/stock-adjustments/{$draft}/cancel"), 'POST', '/stock-adjustments/{id}/cancel', 200);
    c2($s()->getJson('/api/v1/stock-adjustments'), 'GET', '/stock-adjustments', 200);

    $inv = c2($s()->postJson('/api/v1/inventories', ['warehouse_id' => $main]), 'POST', '/inventories', 201)->json('data.id');
    $line = c2($s()->postJson("/api/v1/inventories/{$inv}/start", [], $k()), 'POST', '/inventories/{id}/start', 200)->json('data.lines.0.id');
    c2($s()->putJson("/api/v1/inventories/{$inv}/lines/{$line}", ['qty_counted' => 29]), 'PUT', '/inventories/{id}/lines/{lineId}', 200);
    c2($s()->postJson("/api/v1/inventories/{$inv}/close-counting"), 'POST', '/inventories/{id}/close-counting', 200);
    c2($s()->postJson("/api/v1/inventories/{$inv}/reopen"), 'POST', '/inventories/{id}/reopen', 200);
    $s()->postJson("/api/v1/inventories/{$inv}/close-counting");
    c2($s()->postJson("/api/v1/inventories/{$inv}/validate", [], $k()), 'POST', '/inventories/{id}/validate', 200);
    c2($s()->getJson("/api/v1/inventories/{$inv}"), 'GET', '/inventories/{id}', 200);
    c2($s()->getJson('/api/v1/inventories'), 'GET', '/inventories', 200);
    $cancelled = $s()->postJson('/api/v1/inventories', ['warehouse_id' => $other])->json('data.id');
    c2($s()->postJson("/api/v1/inventories/{$cancelled}/cancel"), 'POST', '/inventories/{id}/cancel', 200);

    $trf = c2($s()->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $main, 'to_warehouse_id' => $other, 'lines' => [['variant_id' => $variant, 'quantity' => 5]]]), 'POST', '/stock-transfers', 201)->json('data.id');
    c2($s()->patchJson("/api/v1/stock-transfers/{$trf}", ['note' => 'Réassort']), 'PATCH', '/stock-transfers/{id}', 200);
    c2($s()->postJson("/api/v1/stock-transfers/{$trf}/confirm", [], $k()), 'POST', '/stock-transfers/{id}/confirm', 200);
    c2($s()->postJson("/api/v1/stock-transfers/{$trf}/ship", [], $k()), 'POST', '/stock-transfers/{id}/ship', 200);
    c2($s()->postJson("/api/v1/stock-transfers/{$trf}/receive", [], $k()), 'POST', '/stock-transfers/{id}/receive', 200);
    c2($s()->getJson("/api/v1/stock-transfers/{$trf}"), 'GET', '/stock-transfers/{id}', 200);
    c2($s()->getJson('/api/v1/stock-transfers'), 'GET', '/stock-transfers', 200);
    $draftTrf = $s()->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $main, 'to_warehouse_id' => $other, 'lines' => [['variant_id' => $variant, 'quantity' => 1]]])->json('data.id');
    c2($s()->postJson("/api/v1/stock-transfers/{$draftTrf}/cancel"), 'POST', '/stock-transfers/{id}/cancel', 200);
    c2($s()->postJson("/api/v1/stock-transfers/{$draftTrf}/confirm", [], $k()), 'POST', '/stock-transfers/{id}/confirm', 409);
});
