<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L6-04 à L6-09 : tableau de bord, alertes, rapports et exports (TEST-017), consolidation (TEST-023), imports (TEST-019), recherche.
*/

it('produit le tableau de bord et les alertes de l\'entreprise', function () {
    $ctx = invoicedOrder(5);
    // La facture est immuable : on se place après son échéance (délai client de 15 jours).
    $this->travel(20)->days();

    $data = loginAs($ctx['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('data');
    expect(array_column($data['alerts'], 'code'))->toContain('overdue_invoices');
});

it('applique la permission du rapport à l\'API comme à l\'export (TEST-017)', function () {
    $ctx = invoicedOrder(5, deliver: true);
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);

    loginAs($seller)->getJson('/api/v1/reports/sales_by_product')->assertForbidden();
    loginAs($seller)->get('/api/v1/reports/sales_by_product?format=csv')->assertForbidden();
    expect(array_column(loginAs($seller)->getJson('/api/v1/reports')->json('data'), 'code'))->not->toContain('sales_by_product');

    $rows = loginAs($ctx['admin'])->getJson('/api/v1/reports/sales_by_product')->assertOk()->json('data');
    expect($rows[0])->toMatchArray(['quantite' => '5', 'ca_net' => '5000.0000', 'cout' => '3000.0000', 'marge' => '2000.0000']);

    $csv = loginAs($ctx['admin'])->get('/api/v1/reports/receivables_aging?format=csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($csv->getContent())->toContain('facture;client;echeance;jours_retard;solde;tranche');
});

it('consolide par devise pour le super-administrateur seulement (TEST-023)', function () {
    $ctx = invoicedOrder(2);
    loginAs($ctx['admin'])->getJson('/api/v1/admin/consolidated')->assertForbidden();

    $data = loginAs(superAdmin())->getJson('/api/v1/admin/consolidated')->assertOk()->json('data');
    expect(collect($data['totals_by_currency'])->firstWhere('currency', 'XOF')['invoiced'])->toBe('2000.0000');
});

it('simule un import sans rien écrire, refuse un fichier invalide, puis importe (TEST-019)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $csv = "Nom;Téléphone;Adresse\nBoutique Fall;771112233;Thiès\nMme Sarr;781234567;Dakar\n";
    $upload = fn (string $content) => UploadedFile::fake()->createWithContent('clients.csv', $content);

    $simulated = loginAs($admin)->post('/api/v1/imports/customers', ['file' => $upload($csv), 'simulate' => true], ['Accept' => 'application/json', 'Idempotency-Key' => Str::random(24)])
        ->assertOk()->json('data');
    expect($simulated)->toMatchArray(['simulated' => true, 'rows' => 2, 'created' => 2, 'errors' => []])
        ->and(DB::table('customers')->where('company_id', $company->id)->where('is_walk_in', false)->count())->toBe(0);

    loginAs($admin)->post('/api/v1/imports/customers', ['file' => $upload("Nom;Téléphone\n;770000000\n"), 'simulate' => false], ['Accept' => 'application/json', 'Idempotency-Key' => Str::random(24)])
        ->assertStatus(422)->assertJsonPath('data.errors.0.field', 'name');

    loginAs($admin)->post('/api/v1/imports/customers', ['file' => $upload($csv), 'simulate' => false], ['Accept' => 'application/json', 'Idempotency-Key' => Str::random(24)])
        ->assertOk()->assertJsonPath('data.created', 2);
    expect(DB::table('customers')->where('company_id', $company->id)->where('phone', '+221771112233')->exists())->toBeTrue();
});

it('recherche dans l\'entreprise active seulement (L6-09)', function () {
    $ctx = invoicedOrder(1);
    $other = provisionedCompany();
    $otherAdmin = userWithRole($other);
    loginAs($otherAdmin)->postJson('/api/v1/customers', ['name' => 'Awa Diop B'])->assertCreated();

    $results = loginAs($ctx['admin'])->getJson('/api/v1/search?q=Awa')->assertOk()->json('data');
    expect(array_column($results, 'label'))->toContain('Awa Diop (+221771234567)')
        ->and(array_column($results, 'label'))->not->toContain('Awa Diop B');
});
