<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| L1-03, TEST-001 : test générique de non-fuite (docs/07 §4.1).
|
| Parcourt toutes les routes /api/v1. Un administrateur de A appelle chaque route avec les identifiants
| des ressources de B : la réponse doit être 404 (403 pour les routes de plateforme) et ne contenir
| aucune donnée de B. Toute nouvelle route avec un paramètre absent du registre fait échouer le test.
*/

const PUBLIC_ROUTES = ['api/v1/health', 'api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password', 'api/v1/auth/accept-invitation', 'api/v1/auth/logout'];

/** Préfixes des routes de plateforme : refusées (403) à un administrateur d'entreprise. */
const PLATFORM_PREFIXES = ['api/v1/admin/', 'api/v1/context/'];

/**
 * Registre : paramètre de route → identifiant d'une ressource de B.
 *
 * @return array<string, Closure(array<string, string>, string=): string>
 */
function resourceRegistry(): array
{
    return [
        'user' => fn (array $b) => $b['user'],
        'role' => fn (array $b) => $b['role'],
        'invitation' => fn (array $b) => $b['invitation'],
        'taxRate' => fn (array $b) => $b['tax_rate'],
        'company' => fn (array $b) => $b['company'],
        'category' => fn (array $b) => $b['category'],
        'attribute' => fn (array $b) => $b['attribute'],
        'product' => fn (array $b) => $b['product'],
        'variant' => fn (array $b) => $b['variant'],
        'unit' => fn (array $b) => $b['unit'],
        'image' => fn (array $b) => $b['image'],
        'priceList' => fn (array $b) => $b['price_list'],
        'warehouse' => fn (array $b) => $b['warehouse'],
        'adjustment' => fn (array $b) => $b['adjustment'],
        'inventory' => fn (array $b) => $b['inventory'],
        'line' => fn (array $b) => $b['inventory_line'],
        'transfer' => fn (array $b) => $b['transfer'],
        'supplier' => fn (array $b) => $b['supplier'],
        // {order} désigne une commande d'achat ou de vente selon la route.
        'order' => fn (array $b, string $uri = '') => str_contains($uri, 'sales-orders') ? $b['order'] : $b['purchase_order'],
        'shipment' => fn (array $b) => $b['shipment'],
        'list' => fn (array $b) => $b['packing_list'],
        'receipt' => fn (array $b) => $b['receipt'],
        'cost' => fn (array $b) => $b['cost'],
        'model' => fn (array $b) => $b['model'],
        'calculation' => fn (array $b) => $b['calculation'],
        'customer' => fn (array $b) => $b['customer'],
        'quotation' => fn (array $b) => $b['quotation'],
        'delivery' => fn (array $b) => $b['delivery'],
        'return' => fn (array $b) => $b['return'],
        'dispute' => fn (array $b) => $b['dispute'],
        'invoice' => fn (array $b) => $b['invoice'],
        'note' => fn (array $b) => $b['note'],
        'payment' => fn (array $b) => $b['payment'],
        'account' => fn (array $b) => $b['account'],
        'expense' => fn (array $b) => $b['expense'],
        'rule' => fn (array $b) => $b['rule'],
        'approval' => fn (array $b) => $b['approval'],
        'notification' => fn (array $b) => $b['notification'],
        // Paramètres non liés à une ressource : une valeur inconnue doit répondre 404.
        'report' => fn () => 'rapport-inconnu',
        'type' => fn () => 'type-inconnu',
    ];
}

/** @return array<string, string> identifiants des ressources créées dans B */
function seedCompanyB(Company $b): array
{
    Mail::fake();
    $adminB = userWithRole($b, attributes: ['name' => 'MARQUEUR-B-USER', 'email' => 'marqueur-b@example.com']);
    $session = loginAs($adminB);

    $role = $session->postJson('/api/v1/roles', ['name' => 'MARQUEUR-B-ROLE', 'permissions' => ['invoices.view']])->assertCreated()->json('data.id');
    $tax = $session->postJson('/api/v1/tax-rates', ['name' => 'MARQUEUR-B-TAX', 'rate' => '3'])->assertCreated()->json('data.id');
    $session->postJson('/api/v1/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'XOF', 'rate' => '655.957', 'valid_on' => '2026-01-01', 'source' => 'MARQUEUR-B-FX'])->assertCreated();
    $invitation = $session->postJson('/api/v1/invitations', ['email' => 'marqueur-b-invite@example.com', 'role_id' => $role])->assertCreated()->json('data.id');
    $session->patchJson('/api/v1/settings/company', ['settings' => ['invoice_footer' => 'MARQUEUR-B-FOOTER']])->assertOk();

    // Catalogue et stock de B.
    $category = $session->postJson('/api/v1/categories', ['name' => 'MARQUEUR-B-CAT'])->assertCreated()->json('data.id');
    $attribute = $session->postJson('/api/v1/attributes', ['name' => 'MARQUEUR-B-ATTR'])->assertCreated()->json('data.id');
    $product = $session->postJson('/api/v1/products', ['reference' => 'MARQUEUR-B-REF', 'name' => 'MARQUEUR-B-PRODUIT', 'category_id' => $category, 'barcode' => 'MARQUEUR-B-EAN', 'pieces_per_carton' => 6])
        ->assertCreated()->json('data');
    $variant = $product['variants'][0]['id'];
    $unit = $product['variants'][0]['units'][1]['id'];
    $priceList = $session->postJson('/api/v1/price-lists', ['code' => 'vip', 'name' => 'MARQUEUR-B-GRILLE', 'currency' => 'XOF'])->assertCreated()->json('data.id');
    $image = $session->post("/api/v1/products/{$product['id']}/images", ['image' => UploadedFile::fake()->image('b.png', 50, 50)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $warehouse = $session->postJson('/api/v1/warehouses', ['code' => 'BWH', 'name' => 'MARQUEUR-B-DEPOT'])->assertCreated()->json('data.id');
    $mainWarehouse = defaultWarehouse($b);
    receiveStock($adminB, $variant, 20);
    $session = loginAs($adminB);
    $adjustment = $session->postJson('/api/v1/stock-adjustments', ['warehouse_id' => $mainWarehouse, 'reason' => 'MARQUEUR-B-AJU', 'lines' => [['variant_id' => $variant, 'quantity' => 1, 'unit_cost' => '10']]])->assertCreated()->json('data.id');
    $inventory = $session->postJson('/api/v1/inventories', ['warehouse_id' => $mainWarehouse, 'note' => 'MARQUEUR-B-INV'])->assertCreated()->json('data.id');
    $inventoryLine = $session->postJson("/api/v1/inventories/{$inventory}/start", [], ['Idempotency-Key' => Str::random(24)])->assertOk()->json('data.lines.0.id');
    $transfer = $session->postJson('/api/v1/stock-transfers', ['from_warehouse_id' => $mainWarehouse, 'to_warehouse_id' => $warehouse, 'note' => 'MARQUEUR-B-TRF', 'lines' => [['variant_id' => $variant, 'quantity' => 2]]])->assertCreated()->json('data.id');

    // Lot 3 : achats, arrivage reçu, packing list, frais, calcul.
    $supplier = $session->postJson('/api/v1/suppliers', ['code' => 'BSUP', 'name' => 'MARQUEUR-B-FOURNISSEUR', 'currency' => 'XOF'])->assertCreated()->json('data.id');
    $purchaseOrder = $session->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'notes' => 'MARQUEUR-B-BC', 'lines' => [['variant_id' => $variant, 'qty_units' => 2, 'unit_price' => '10']]])->assertCreated()->json('data.id');
    $shipment = $session->postJson('/api/v1/shipments', ['reference' => 'MARQUEUR-B-ARR', 'default_warehouse_id' => $mainWarehouse])->assertCreated()->json('data.id');
    $session->postJson("/api/v1/shipments/{$shipment}/lines", ['lines' => [['variant_id' => $variant, 'qty_expected' => 2, 'cbm_total' => '1', 'purchase_currency' => 'XOF', 'purchase_unit_price' => '10']]])->assertCreated();
    $packingList = $session->post("/api/v1/shipments/{$shipment}/packing-lists", ['file' => UploadedFile::fake()->createWithContent('b.csv', "Item No;Quantité\nMARQUEUR-B-REF;2\n")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $session->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => 'shipped'], ['Idempotency-Key' => Str::random(24)])->assertOk();
    $session->postJson("/api/v1/shipments/{$shipment}/transition", ['to' => 'arrived'], ['Idempotency-Key' => Str::random(24)])->assertOk();
    $category = $session->getJson('/api/v1/landed-cost-categories')->json('data.0.id');
    $cost = $session->postJson("/api/v1/shipments/{$shipment}/costs", ['category_id' => $category, 'currency' => 'XOF', 'amount' => '100', 'description' => 'MARQUEUR-B-FRAIS'])->assertCreated()->json('data.id');
    $receiptData = $session->postJson('/api/v1/goods-receipts', ['shipment_id' => $shipment, 'warehouse_id' => $mainWarehouse, 'note' => 'MARQUEUR-B-BR'])->assertCreated()->json('data');
    $receipt = $receiptData['id'];
    $session->putJson("/api/v1/goods-receipts/{$receipt}/lines/{$receiptData['lines'][0]['id']}", ['qty_accepted' => 2])->assertOk();
    $session->postJson("/api/v1/goods-receipts/{$receipt}/validate", [], ['Idempotency-Key' => Str::random(24)])->assertOk();
    $model = $session->getJson('/api/v1/cost-models')->json('data.0.id');
    $calculation = $session->postJson("/api/v1/shipments/{$shipment}/landed-costs", [], ['Idempotency-Key' => Str::random(24)])->assertCreated()->json('data.id');

    // Lot 4 : client, devis, commande livrée et réglée, retour, litige, avoir.
    $customer = $session->postJson('/api/v1/customers', ['name' => 'MARQUEUR-B-CLIENT', 'phone' => '770000001'])->assertCreated()->json('data.id');
    $retail = DB::table('price_lists')->where('company_id', $b->id)->where('code', 'retail')->value('id');
    $quotation = $session->postJson('/api/v1/quotations', ['customer_id' => $customer, 'price_list_id' => $retail, 'notes' => 'MARQUEUR-B-DEVIS', 'lines' => [['variant_id' => $variant, 'qty_units' => 1, 'unit_price' => '10']]])->assertCreated()->json('data.id');
    $sale = $session->postJson('/api/v1/sales-orders', ['customer_id' => $customer, 'price_list_id' => $retail, 'warehouse_id' => $mainWarehouse, 'notes' => 'MARQUEUR-B-CMD', 'lines' => [['variant_id' => $variant, 'qty_units' => 1, 'unit_price' => '10']]])->assertCreated()->json('data');
    $invoice = $session->postJson("/api/v1/sales-orders/{$sale['id']}/submit", [], ['Idempotency-Key' => Str::random(24)])->assertOk()->json('data.invoice_id');
    $delivery = $session->postJson("/api/v1/sales-orders/{$sale['id']}/deliveries")->assertCreated()->json('data.id');
    $session->postJson("/api/v1/deliveries/{$delivery}/ready")->assertOk();
    $session->postJson("/api/v1/deliveries/{$delivery}/deliver", [], ['Idempotency-Key' => Str::random(24)])->assertOk();
    $account = DB::table('financial_accounts')->where('company_id', $b->id)->where('account_type', 'cash')->value('id');
    $payment = $session->postJson("/api/v1/invoices/{$invoice}/payments", ['method' => 'cash', 'financial_account_id' => $account, 'amount' => '5'], ['Idempotency-Key' => Str::random(24)])->assertCreated()->json('data.id');
    $return = $session->postJson('/api/v1/returns', ['sales_order_id' => $sale['id'], 'return_type' => 'other', 'reason' => 'MARQUEUR-B-RET', 'lines' => [$sale['lines'][0]['id'] => 1]])->assertCreated()->json('data.id');
    $dispute = $session->postJson("/api/v1/sales-orders/{$sale['id']}/disputes", ['reason' => 'MARQUEUR-B-LITIGE'])->assertCreated()->json('data.id');
    // Lots 5 et 6 : dépense, règle et demande d'approbation, notification.
    $expenseCategory = DB::table('expense_categories')->where('company_id', $b->id)->value('id');
    $expense = $session->postJson('/api/v1/expenses', ['category_id' => $expenseCategory, 'financial_account_id' => $account, 'expense_date' => '2026-11-06', 'label' => 'MARQUEUR-B-DEP', 'amount' => '1'], ['Idempotency-Key' => Str::random(24)])->assertCreated()->json('data.id');
    $rule = $session->postJson('/api/v1/approval-rules', ['name' => 'MARQUEUR-B-REGLE', 'operation' => 'financial_correction', 'threshold_type' => 'always'])->assertCreated()->json('data.id');
    $approval = $session->postJson('/api/v1/financial-corrections', ['financial_account_id' => $account, 'direction' => 'in', 'amount' => '1', 'reason' => 'MARQUEUR-B-CORR'], ['Idempotency-Key' => Str::random(24)])->assertCreated()->json('meta.approval_request_id');
    $notification = DB::table('notifications_inbox')->where('company_id', $b->id)->value('id');
    $note = $session->postJson('/api/v1/credit-notes', ['customer_id' => $customer, 'kind' => 'commercial', 'reason' => 'MARQUEUR-B-AVOIR', 'currency' => 'XOF', 'lines' => [['description' => 'x', 'amount' => '1']]])->assertCreated()->json('data.id');

    auth('web')->logout();
    app('auth')->forgetGuards();

    return ['customer' => $customer, 'quotation' => $quotation, 'order' => $sale['id'], 'delivery' => $delivery, 'return' => $return, 'dispute' => $dispute,
        'invoice' => $invoice, 'note' => $note, 'payment' => $payment, 'account' => $account,
        'expense' => $expense, 'rule' => $rule, 'approval' => $approval, 'notification' => $notification,
        'supplier' => $supplier, 'purchase_order' => $purchaseOrder, 'shipment' => $shipment, 'packing_list' => $packingList,
        'receipt' => $receipt, 'cost' => $cost, 'model' => $model, 'calculation' => $calculation,
        'company' => $b->id, 'user' => $adminB->id, 'role' => $role, 'tax_rate' => $tax, 'invitation' => $invitation,
        'category' => $category, 'attribute' => $attribute, 'product' => $product['id'], 'variant' => $variant, 'unit' => $unit,
        'image' => $image, 'price_list' => $priceList, 'warehouse' => $warehouse, 'adjustment' => $adjustment,
        'inventory' => $inventory, 'inventory_line' => $inventoryLine, 'transfer' => $transfer];
}

/** @return list<Route> */
function apiRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn (Route $route) => str_starts_with($route->uri(), 'api/v1/') && ! str_starts_with($route->uri(), 'api/v1/_test'),
    ));
}

it('n\'expose aucune ressource de B à un administrateur de A, sur toutes les routes', function () {
    Storage::fake('private');
    $a = provisionedCompany();
    $b = provisionedCompany();
    $idsOfB = seedCompanyB($b);
    $adminA = userWithRole($a, RoleTemplate::CompanyAdmin);
    $registry = resourceRegistry();
    $markers = [...array_values($idsOfB), 'MARQUEUR-B', 'marqueur-b'];
    $checked = 0;

    foreach (apiRoutes() as $route) {
        if (in_array($route->uri(), PUBLIC_ROUTES, true)) {
            continue;
        }

        $uri = $route->uri();

        foreach ($route->parameterNames() as $parameter) {
            expect(array_key_exists($parameter, $registry))->toBeTrue("Route {$uri} : paramètre {{$parameter}} absent du registre de non-fuite.");
            $uri = str_replace('{'.$parameter.'}', $registry[$parameter]($idsOfB, $route->uri()), $uri);
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $headers = ['Idempotency-Key' => Str::random(24)];
            $response = loginAs($adminA)->json($method, '/'.$uri, [], $headers);
            $platform = (bool) array_filter(PLATFORM_PREFIXES, fn ($p) => str_starts_with($route->uri(), $p));
            $label = "{$method} /{$route->uri()}";

            if ($platform) {
                expect($response->status())->toBe(403, "{$label} doit être refusée (403) à un administrateur d'entreprise.");
            } elseif ($route->parameterNames() !== []) {
                expect($response->status())->toBe(404, "{$label} avec un identifiant de B doit répondre 404, reçu {$response->status()}.");
            } else {
                expect($response->status())->toBeLessThan(500, "{$label} a échoué : {$response->getContent()}");
            }

            // Le chemin demandé (renvoyé dans « instance ») contient forcément l'identifiant de B.
            $content = str_replace('/'.$uri, '', (string) $response->getContent());

            foreach ($markers as $marker) {
                expect(str_contains($content, $marker))->toBeFalse("{$label} expose une donnée de B ({$marker}).");
            }

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(20);

    // Rien n'a été écrit chez B par A.
    expect(DB::table('tax_rates')->where('company_id', $b->id)->count())->toBe(1)
        ->and(User::query()->where('company_id', $b->id)->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('company_id', $b->id)->count())->toBe(3)
        ->and(DB::table('products')->where('company_id', $b->id)->count())->toBe(1);
});
