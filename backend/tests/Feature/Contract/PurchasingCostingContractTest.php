<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

/*
| DoD §3 : les opérations du lot 3 respectent le contrat openapi/openapi.yaml.
*/

function c3(TestResponse $response, string $method, string $path, int $status): TestResponse
{
    $response->assertStatus($status);
    OpenApiContract::assertResponseMatches($response, $method, $path);

    return $response;
}

function k3(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

it('respecte le contrat : achats, arrivages, réceptions, packing lists, coûts de revient', function () {
    Storage::fake('private');
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $s = fn () => loginAs($admin);
    $variant = createVariant($company, ['reference' => 'CT-REF']);
    $wh = defaultWarehouse($company);

    // Achats
    $supplier = c3($s()->postJson('/api/v1/suppliers', ['code' => 'CAN', 'name' => 'Canton Trading', 'currency' => 'XOF', 'country_code' => 'CN']), 'POST', '/suppliers', 201)->json('data.id');
    c3($s()->getJson('/api/v1/suppliers'), 'GET', '/suppliers', 200);
    c3($s()->getJson("/api/v1/suppliers/{$supplier}"), 'GET', '/suppliers/{id}', 200);
    c3($s()->patchJson("/api/v1/suppliers/{$supplier}", ['phone' => '+221 77 000 00 00']), 'PATCH', '/suppliers/{id}', 200);
    $order = c3($s()->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'lines' => [['variant_id' => $variant, 'qty_units' => 20, 'unit_price' => '1000']]]), 'POST', '/purchase-orders', 201)->json('data.id');
    c3($s()->patchJson("/api/v1/purchase-orders/{$order}", ['notes' => 'Urgent', 'version' => 1]), 'PATCH', '/purchase-orders/{id}', 200);
    c3($s()->postJson("/api/v1/purchase-orders/{$order}/confirm", [], k3()), 'POST', '/purchase-orders/{id}/confirm', 200);
    c3($s()->getJson('/api/v1/purchase-orders'), 'GET', '/purchase-orders', 200);
    c3($s()->getJson("/api/v1/purchase-orders/{$order}"), 'GET', '/purchase-orders/{id}', 200);
    c3($s()->postJson('/api/v1/supplier-bills', ['supplier_id' => $supplier, 'amount' => '-500', 'bill_date' => '2026-11-06'], k3()), 'POST', '/supplier-bills', 201);
    c3($s()->getJson('/api/v1/supplier-bills'), 'GET', '/supplier-bills', 200);
    c3($s()->getJson("/api/v1/suppliers/{$supplier}/statement"), 'GET', '/suppliers/{id}/statement', 200);
    $cancelled = $s()->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'lines' => [['variant_id' => $variant, 'qty_units' => 1, 'unit_price' => '1']]])->json('data.id');
    c3($s()->postJson("/api/v1/purchase-orders/{$cancelled}/cancel"), 'POST', '/purchase-orders/{id}/cancel', 200);

    // Arrivage
    $shipment = c3($s()->postJson('/api/v1/shipments', ['reference' => 'MSKU-1', 'default_warehouse_id' => $wh, 'eta' => '2026-12-01']), 'POST', '/shipments', 201)->json('data.id');
    c3($s()->patchJson("/api/v1/shipments/{$shipment}", ['carrier' => 'Maersk']), 'PATCH', '/shipments/{shipment}', 200);
    $container = c3($s()->postJson("/api/v1/shipments/{$shipment}/containers", ['container_number' => 'MSKU1234567', 'container_type' => '40HC']), 'POST', '/shipments/{shipment}/containers', 201)->json('data.id');
    c3($s()->getJson("/api/v1/shipments/{$shipment}/containers"), 'GET', '/shipments/{shipment}/containers', 200);
    $shp = c3($s()->postJson("/api/v1/shipments/{$shipment}/purchase-orders", ['purchase_order_id' => $order]), 'POST', '/shipments/{shipment}/purchase-orders', 200)->json('data');
    $line = $shp['lines'][0]['id'];
    c3($s()->patchJson("/api/v1/shipments/{$shipment}/lines/{$line}", ['cbm_total' => '1.5', 'container_id' => $container]), 'PATCH', '/shipments/{shipment}/lines/{line}', 200);
    $extra = c3($s()->postJson("/api/v1/shipments/{$shipment}/lines", ['lines' => [['variant_id' => $variant, 'qty_expected' => 3, 'purchase_currency' => 'XOF', 'purchase_unit_price' => '10']]]), 'POST', '/shipments/{shipment}/lines', 201)->json('data.lines.1.id');
    c3($s()->deleteJson("/api/v1/shipments/{$shipment}/lines/{$extra}"), 'DELETE', '/shipments/{shipment}/lines/{line}', 204);
    c3($s()->getJson("/api/v1/shipments/{$shipment}/lines"), 'GET', '/shipments/{shipment}/lines', 200);

    // Packing list
    $csv = "Item No;Description;Quantité;CBM;Unit Price\nCT-REF;Article;20;1,5;1000\n";
    $list = c3($s()->post("/api/v1/shipments/{$shipment}/packing-lists", ['file' => UploadedFile::fake()->createWithContent('pl.csv', $csv), 'supplier_id' => $supplier], ['Accept' => 'application/json']),
        'POST', '/shipments/{shipment}/packing-lists', 201)->json('data');
    c3($s()->getJson("/api/v1/shipments/{$shipment}/packing-lists"), 'GET', '/shipments/{shipment}/packing-lists', 200);
    c3($s()->getJson("/api/v1/packing-lists/{$list['id']}"), 'GET', '/packing-lists/{list}', 200);
    c3($s()->getJson("/api/v1/packing-lists/{$list['id']}/lines"), 'GET', '/packing-lists/{list}/lines', 200);
    c3($s()->patchJson("/api/v1/packing-lists/{$list['id']}/lines/{$list['lines'][0]['id']}", ['action' => 'confirm']), 'PATCH', '/packing-lists/{list}/lines/{line}', 200);
    c3($s()->postJson("/api/v1/packing-lists/{$list['id']}/validate", [], k3()), 'POST', '/packing-lists/{list}/validate', 200);
    $rejected = $s()->post("/api/v1/shipments/{$shipment}/packing-lists", ['file' => UploadedFile::fake()->createWithContent('pl2.csv', $csv)], ['Accept' => 'application/json'])->json('data.id');
    c3($s()->postJson("/api/v1/packing-lists/{$rejected}/reject"), 'POST', '/packing-lists/{list}/reject', 200);
    c3($s()->postJson('/api/v1/import-mapping-templates', ['import_type' => 'packing_list', 'name' => 'Canton', 'file_format' => 'csv', 'mapping' => ['header_row' => 1, 'columns' => ['total_qty' => 'Quantité']]]),
        'POST', '/import-mapping-templates', 201);
    c3($s()->getJson('/api/v1/import-mapping-templates'), 'GET', '/import-mapping-templates', 200);

    c3($s()->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => 'shipped'], k3()), 'POST', '/shipments/{shipment}/transition', 200);
    c3($s()->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => 'arrived'], k3()), 'POST', '/shipments/{shipment}/transition', 200);
    c3($s()->getJson('/api/v1/shipments'), 'GET', '/shipments', 200);
    c3($s()->getJson("/api/v1/shipments/{$shipment}"), 'GET', '/shipments/{shipment}', 200);

    // Frais et réception
    $category = c3($s()->getJson('/api/v1/landed-cost-categories'), 'GET', '/landed-cost-categories', 200)->json('data.0.id');
    c3($s()->postJson('/api/v1/landed-cost-categories', ['code' => 'storage', 'label' => 'Magasinage']), 'POST', '/landed-cost-categories', 201);
    $cost = c3($s()->postJson("/api/v1/shipments/{$shipment}/costs", ['category_id' => $category, 'currency' => 'XOF', 'amount' => '30000']), 'POST', '/shipments/{shipment}/costs', 201)->json('data.id');
    c3($s()->patchJson("/api/v1/shipments/{$shipment}/costs/{$cost}", ['description' => 'Fret']), 'PATCH', '/shipments/{shipment}/costs/{cost}', 200);
    c3($s()->postJson("/api/v1/shipments/{$shipment}/costs/{$cost}/confirm", [], k3()), 'POST', '/shipments/{shipment}/costs/{cost}/confirm', 200);
    $spare = $s()->postJson("/api/v1/shipments/{$shipment}/costs", ['category_id' => $category, 'currency' => 'XOF', 'amount' => '1'])->json('data.id');
    c3($s()->postJson("/api/v1/shipments/{$shipment}/costs/{$spare}/cancel"), 'POST', '/shipments/{shipment}/costs/{cost}/cancel', 200);
    c3($s()->getJson("/api/v1/shipments/{$shipment}/costs"), 'GET', '/shipments/{shipment}/costs', 200);

    $receipt = c3($s()->postJson('/api/v1/goods-receipts', ['shipment_id' => $shipment, 'warehouse_id' => $wh, 'counting_mode' => 'scan']), 'POST', '/goods-receipts', 201)->json('data');
    c3($s()->postJson("/api/v1/goods-receipts/{$receipt['id']}/start"), 'POST', '/goods-receipts/{receipt}/start', 200);
    c3($s()->postJson("/api/v1/goods-receipts/{$receipt['id']}/scans", ['client_event_id' => 'e1', 'code' => 'INCONNU']), 'POST', '/goods-receipts/{receipt}/scans', 201);
    c3($s()->putJson("/api/v1/goods-receipts/{$receipt['id']}/lines/{$receipt['lines'][0]['id']}", ['qty_accepted' => 20]), 'PUT', '/goods-receipts/{receipt}/lines/{line}', 200);
    c3($s()->postJson("/api/v1/goods-receipts/{$receipt['id']}/validate", [], k3()), 'POST', '/goods-receipts/{receipt}/validate', 200);
    c3($s()->getJson('/api/v1/goods-receipts'), 'GET', '/goods-receipts', 200);
    c3($s()->getJson("/api/v1/goods-receipts/{$receipt['id']}"), 'GET', '/goods-receipts/{receipt}', 200);

    // Modèles et calcul
    $model = c3($s()->getJson('/api/v1/cost-models'), 'GET', '/cost-models', 200)->json('data.0.id');
    $custom = c3($s()->postJson('/api/v1/cost-models', ['code' => 'MAJ', 'name' => 'Majoré', 'expression' => 'purchase_converted * 1.01 + common_fees']), 'POST', '/cost-models', 201)->json('data.id');
    c3($s()->postJson("/api/v1/cost-models/{$custom}/test"), 'POST', '/cost-models/{model}/test', 200);
    c3($s()->postJson("/api/v1/cost-models/{$custom}/activate"), 'POST', '/cost-models/{model}/activate', 200);
    c3($s()->postJson("/api/v1/cost-models/{$custom}/versions", ['expression' => 'purchase_converted + common_fees + direct_fees']), 'POST', '/cost-models/{model}/versions', 201);
    $calc = c3($s()->postJson("/api/v1/shipments/{$shipment}/landed-costs", ['model_id' => $model], k3()), 'POST', '/shipments/{shipment}/landed-costs', 201)->json('data.id');
    c3($s()->getJson("/api/v1/shipments/{$shipment}/landed-costs"), 'GET', '/shipments/{shipment}/landed-costs', 200);
    c3($s()->getJson("/api/v1/landed-costs/{$calc}"), 'GET', '/landed-costs/{calculation}', 200);
    c3($s()->postJson("/api/v1/landed-costs/{$calc}/finalize", [], k3()), 'POST', '/landed-costs/{calculation}/finalize', 200);
    c3($s()->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => 'closed'], k3()), 'POST', '/shipments/{shipment}/transition', 200);
});
