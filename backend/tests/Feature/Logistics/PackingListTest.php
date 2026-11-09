<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| L3-05, L3-06 : packing list Excel/CSV → revue → articles attendus (IMP-001, IMP-006, CR-009).
*/

function plShipment(): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $known = createVariant($company, ['reference' => 'CHAISE-01']);
    $supplier = loginAs($admin)->postJson('/api/v1/suppliers', ['code' => 'GZ', 'name' => 'Guangzhou Furniture', 'currency' => 'USD'])->json('data.id');
    $shipment = loginAs($admin)->postJson('/api/v1/shipments', ['default_warehouse_id' => defaultWarehouse($company)])->json('data.id');

    return compact('company', 'admin', 'known', 'supplier', 'shipment');
}

function xlsxUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'packing-list.xlsx', null, null, true);
}

it('extrait une packing list Excel, suggère les rapprochements et ne touche pas au stock (IMP-001)', function () {
    $ctx = plShipment();
    $file = xlsxUpload([
        ['PACKING LIST — Guangzhou Furniture'],
        [],
        ['Item No', 'Description', 'CTNS', 'PCS/CTN', 'Total PCS', 'CBM/CTN', 'Unit Price', 'Amount'],
        ['CHAISE-01', 'Chaise pliante', 10, 4, 40, 0.25, 12.5, 500],
        ['TABLE-77', 'Table basse', 5, 1, 5, 0.4, 45, 225],
    ]);

    $list = loginAs($ctx['admin'])->post("/api/v1/shipments/{$ctx['shipment']}/packing-lists", ['file' => $file, 'supplier_id' => $ctx['supplier']])
        ->assertCreated()->json('data');

    expect($list['status'])->toBe('in_review')->and($list['currency'])->toBe('USD')
        ->and($list['parse_report'])->toMatchArray(['rows' => 2, 'suggested' => 1])
        ->and($list['lines'][0])->toMatchArray(['supplier_reference' => 'CHAISE-01', 'total_qty' => 40, 'cbm' => '2.500000', 'match_status' => 'suggested'])
        ->and($list['lines'][0]['matched_variant']['id'])->toBe($ctx['known'])
        ->and($list['lines'][1]['match_status'])->toBe('unmatched')
        ->and(DB::table('stock_movements')->where('company_id', $ctx['company']->id)->count())->toBe(0);

    // IMP-006 : validation refusée tant qu'une ligne n'est pas rattachée.
    loginAs($ctx['admin'])->postJson("/api/v1/packing-lists/{$list['id']}/validate", [], ['Idempotency-Key' => Str::random(24)])
        ->assertStatus(422)->assertJsonPath('code', 'packing_list_unresolved');

    loginAs($ctx['admin'])->patchJson("/api/v1/packing-lists/{$list['id']}/lines/{$list['lines'][0]['id']}", ['action' => 'confirm'])->assertOk()->assertJsonPath('data.match_status', 'confirmed');
    loginAs($ctx['admin'])->patchJson("/api/v1/packing-lists/{$list['id']}/lines/{$list['lines'][1]['id']}", ['action' => 'new_product', 'new_product' => ['name' => 'Table basse chêne']])
        ->assertOk()->assertJsonPath('data.match_status', 'new_product')->assertJsonPath('data.matched_variant.reference', 'TABLE-77');

    loginAs($ctx['admin'])->postJson("/api/v1/packing-lists/{$list['id']}/validate", [], ['Idempotency-Key' => Str::random(24)])->assertOk()->assertJsonPath('data.status', 'validated');

    $lines = loginAs($ctx['admin'])->getJson("/api/v1/shipments/{$ctx['shipment']}/lines")->json('data');
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatchArray(['qty_expected' => 40, 'cartons' => 10, 'cbm_total' => '2.500000', 'purchase_currency' => 'USD', 'purchase_unit_price' => '12.50000000'])
        ->and($lines[1])->toMatchArray(['qty_expected' => 5, 'purchase_unit_price' => '45.00000000'])
        ->and($lines[0]['packing_list_line_id'])->toBe($list['lines'][0]['id']);
});

it('lit un CSV au format français et signale les incohérences de quantités', function () {
    $ctx = plShipment();
    $csv = "Référence;Désignation;Colis;Pièces par carton;Quantité;Volume;Prix unitaire\n"
        ."CHAISE-01;Chaise;3;10;30;0,6;1 250,50\n"
        ."CHAISE-01;Chaise abîmée;2;10;25;0,4;1 250,50\n";
    $file = UploadedFile::fake()->createWithContent('pl.csv', $csv);

    $list = loginAs($ctx['admin'])->post("/api/v1/shipments/{$ctx['shipment']}/packing-lists", ['file' => $file, 'currency' => 'XOF'])->assertCreated()->json('data');

    expect($list['lines'][0])->toMatchArray(['total_qty' => 30, 'cbm' => '0.600000', 'unit_price' => '1250.50000000', 'errors' => []])
        ->and($list['lines'][1]['errors'][0]['field'])->toBe('total_qty');

    loginAs($ctx['admin'])->patchJson("/api/v1/packing-lists/{$list['id']}/lines/{$list['lines'][1]['id']}", ['total_qty' => 20, 'action' => 'confirm'])
        ->assertOk()->assertJsonPath('data.errors', []);
});

it('refuse une packing list PDF tant que l\'extraction n\'est pas disponible (L3-07)', function () {
    $ctx = plShipment();
    $pdf = UploadedFile::fake()->createWithContent('pl.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    loginAs($ctx['admin'])->post("/api/v1/shipments/{$ctx['shipment']}/packing-lists", ['file' => $pdf])->assertStatus(422)->assertJsonPath('code', 'pdf_not_supported');
});
