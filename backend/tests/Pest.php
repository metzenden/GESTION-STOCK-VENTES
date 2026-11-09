<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\PriceService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\IdentityAccess\Application\Authenticator;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
| Unit         : domaine pur, sans application Laravel ni base.
| Feature      : application complète, MySQL réel, transaction annulée après chaque test.
| Concurrency  : processus PHP séparés ; les données doivent être validées (commit), d'où la troncature.
| Architecture : règles de dépendances entre modules.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

/** Définit l'entreprise active pour le test courant. */
function actingAsCompany(Company $company): Company
{
    app(TenantContext::class)->set($company->id);

    return $company;
}

/** Entreprise prête à l'emploi : paramètres, séquences et rôles par défaut. */
function provisionedCompany(array $attributes = []): Company
{
    return Company::factory()->provisioned()->create($attributes);
}

/** Utilisateur actif de l'entreprise, avec le rôle par défaut indiqué. */
function userWithRole(Company $company, RoleTemplate $template = RoleTemplate::CompanyAdmin, array $attributes = []): User
{
    $user = User::factory()->forCompany($company)->create($attributes)->refresh();
    $role = Role::query()->where('company_id', $company->id)->where('template_code', $template->value)->firstOrFail();

    app(TenantContext::class)->runAs($company->id, fn () => $user->assignRole($role));

    return $user->refresh();
}

function superAdmin(): User
{
    return User::factory()->superAdmin()->create()->refresh();
}

/**
 * Session authentifiée comme après une vraie connexion. $epoch permet de simuler une session ouverte
 * avant une révocation. Les gardes sont réinitialisés : l'application de test est partagée entre requêtes.
 */
function loginAs(User $user, array $session = [], ?int $epoch = null): TestCase
{
    /** @var TestCase $test */
    $test = test();
    app('auth')->forgetGuards();

    return $test->actingAs($user, 'web')->withSession([Authenticator::SESSION_EPOCH_KEY => $epoch ?? $user->session_epoch] + $session);
}

/** Variante stockée prête à l'emploi : produit créé par le service du catalogue. */
function createVariant(Company $company, array $data = []): string
{
    return app(TenantContext::class)->runAs($company->id, function () use ($data) {
        $product = app(ProductService::class)->create($data + [
            'reference' => 'REF-'.strtoupper(Str::random(6)),
            'name' => 'Article de test',
        ], null);

        return ProductVariant::query()->where('product_id', $product->id)->value('id');
    });
}

function defaultWarehouse(Company $company): string
{
    return (string) DB::table('company_settings')->where('company_id', $company->id)->value('default_sales_warehouse_id');
}

/** Entrée de stock directe (lot + mouvement), exécutée par l'utilisateur indiqué ; renvoie l'identifiant du lot. */
function receiveStock(User $user, string $variantId, int $qty, string $cost = '1000', ?string $warehouseId = null, ?string $receivedAt = null): string
{
    $company = Company::query()->findOrFail($user->company_id);
    app('auth')->guard('web')->setUser($user);

    return app(TenantContext::class)->runAs($company->id, fn () => DB::transaction(function () use ($variantId, $qty, $cost, $warehouseId, $company, $receivedAt) {
        $ledger = app(StockLedger::class);
        $at = CarbonImmutable::parse($receivedAt ?? 'now');
        $lotId = $ledger->createLot($variantId, 'opening', null, $at, $qty, $cost, 'XOF');
        $line = (string) Str::ulid();
        $ledger->receive($warehouseId ?? defaultWarehouse($company), $lotId, $qty, 'opening', new StockSource('opening', $line, $line), $at);

        return $lotId;
    }));
}

/** Compartiments d'une position (dépôt, variante). */
function position(string $warehouseId, string $variantId): array
{
    $p = DB::table('stock_positions')->where(['warehouse_id' => $warehouseId, 'variant_id' => $variantId])->first();

    return $p === null ? ['on_hand' => 0, 'reserved' => 0, 'blocked' => 0, 'in_delivery' => 0, 'available' => 0]
        : ['on_hand' => (int) $p->qty_on_hand, 'reserved' => (int) $p->qty_reserved, 'blocked' => (int) $p->qty_blocked, 'in_delivery' => (int) $p->qty_in_delivery, 'available' => (int) $p->qty_available];
}

// --- Ventes (lots 4 à 7) -----------------------------------------------------

function salesKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

/** Article au prix de détail $price la pièce (carton de $perCarton), avec $stock pièces au coût $cost. */
function sellable($company, $admin, string $price = '1000', int $stock = 100, string $cost = '600', int $perCarton = 6): array
{
    $variant = createVariant($company, ['pieces_per_carton' => $perCarton]);
    $lot = receiveStock($admin, $variant, $stock, $cost);
    app(TenantContext::class)->runAs($company->id, function () use ($variant, $price) {
        $list = DB::table('price_lists')->where('company_id', app(TenantContext::class)->requireCompanyId())->where('code', 'retail')->value('id');
        $piece = DB::table('variant_units')->where('variant_id', $variant)->where('factor', 1)->value('id');
        app(PriceService::class)->add($variant, (string) $list, (string) $piece, $price, CarbonImmutable::parse('2026-01-01'), null, null);
    });
    $units = DB::table('variant_units')->where('variant_id', $variant)->pluck('id', 'factor');

    return ['variant' => $variant, 'lot' => $lot, 'piece' => $units[1], 'carton' => $units[$perCarton] ?? null];
}

function salesSetup(): array
{
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $item = sellable($company, $admin);
    $customer = loginAs($admin)->postJson('/api/v1/customers', ['name' => 'Awa Diop', 'phone' => '77 123 45 67', 'payment_terms_days' => 15])->assertCreated()->json('data');

    return compact('company', 'admin', 'item', 'customer') + ['wh' => defaultWarehouse($company)];
}

function account($company, string $type): string
{
    return (string) DB::table('financial_accounts')->where('company_id', $company->id)->where('account_type', $type)->value('id');
}

/** Commande confirmée de $qty pièces à 1 000 (facture de $qty × 1 000), livrée ou non. */
function invoicedOrder(int $qty = 100, bool $deliver = false): array
{
    $ctx = salesSetup();
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => $qty]]])->json('data');
    $confirmed = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], salesKey())->json('data');

    if ($deliver) {
        $d = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/deliveries")->json('data');
        loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d['id']}/ready")->assertOk();
        loginAs($ctx['admin'])->postJson("/api/v1/deliveries/{$d['id']}/deliver", [], salesKey())->assertOk();
    }

    return $ctx + ['order' => $confirmed, 'invoice' => $confirmed['invoice_id']];
}
