<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| L2-01 à L2-05 : catégories, produits et variantes, conditionnements, codes-barres, prix, photos.
*/

function catalogAdmin(): array
{
    $company = provisionedCompany();

    return [$company, userWithRole($company)];
}

it('crée un produit simple avec sa variante par défaut, l\'unité pièce et le carton', function () {
    [, $admin] = catalogAdmin();

    $response = loginAs($admin)->postJson('/api/v1/products', [
        'reference' => 'TSH-001', 'name' => 'T-shirt coton', 'pieces_per_carton' => 6, 'barcode' => '6001234567890', 'carton_barcode' => '16001234567897',
    ])->assertCreated();

    $variant = $response->json('data.variants.0');
    expect($response->json('data.has_variants'))->toBeFalse()
        ->and($variant['sku'])->toBe('TSH-001')
        ->and($variant['is_default'])->toBeTrue()
        ->and(collect($variant['units'])->map(fn ($u) => [$u['code'], $u['factor'], $u['barcode']])->all())
        ->toBe([['piece', 1, '6001234567890'], ['carton', 6, '16001234567897']]);
});

it('crée un produit à variantes avec attributs', function () {
    [, $admin] = catalogAdmin();
    $color = loginAs($admin)->postJson('/api/v1/attributes', ['name' => 'Couleur'])->json('data.id');
    $red = loginAs($admin)->postJson("/api/v1/attributes/{$color}/values", ['value' => 'Rouge'])->json('data.values.0.id');

    $response = loginAs($admin)->postJson('/api/v1/products', [
        'reference' => 'ROBE', 'name' => 'Robe', 'variants' => [
            ['sku' => 'ROBE-R-M', 'label' => 'Rouge / M', 'attribute_value_ids' => [$red]],
            ['sku' => 'ROBE-R-L', 'label' => 'Rouge / L', 'attribute_value_ids' => [$red]],
        ],
    ])->assertCreated();

    expect($response->json('data.has_variants'))->toBeTrue()
        ->and($response->json('data.variants'))->toHaveCount(2)
        ->and($response->json('data.variants.0.attribute_value_ids'))->toBe([$red]);
});

it('impose l\'unicité de la référence, du SKU et du code-barres dans l\'entreprise (CAT-001)', function () {
    [, $admin] = catalogAdmin();
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'A1', 'name' => 'A', 'barcode' => '111'])->assertCreated();

    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'A1', 'name' => 'Doublon'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/reference');
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'B1', 'name' => 'B', 'variants' => [['sku' => 'A1']]])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/variants/0/sku');
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'C1', 'name' => 'C', 'barcode' => '111'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/barcode');

    // Une autre entreprise peut réutiliser les mêmes codes.
    [, $other] = catalogAdmin();
    loginAs($other)->postJson('/api/v1/products', ['reference' => 'A1', 'name' => 'A', 'barcode' => '111'])->assertCreated();
});

it('refuse de supprimer un produit avec historique, même à stock nul ; l\'archive reste possible (CAT-006)', function () {
    [$company, $admin] = catalogAdmin();
    $free = loginAs($admin)->postJson('/api/v1/products', ['reference' => 'LIBRE', 'name' => 'Sans historique'])->json('data.id');
    loginAs($admin)->deleteJson("/api/v1/products/{$free}")->assertNoContent();

    $variant = createVariant($company);
    $product = DB::table('product_variants')->where('id', $variant)->value('product_id');
    $lot = receiveStock($admin, $variant, 3);
    actingAsCompany($company);
    DB::transaction(fn () => app(StockLedger::class)->issue(defaultWarehouse($company), $lot, 3, 'loss', new StockSource('test', (string) Str::ulid(), (string) Str::ulid()), now()));

    loginAs($admin)->deleteJson("/api/v1/products/{$product}")->assertStatus(409)->assertJsonPath('code', 'has_history');
    loginAs($admin)->postJson("/api/v1/products/{$product}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
});

it('fige le facteur d\'un conditionnement utilisé (CAT-007)', function () {
    [$company, $admin] = catalogAdmin();
    $product = loginAs($admin)->postJson('/api/v1/products', ['reference' => 'K', 'name' => 'K', 'pieces_per_carton' => 12])->json('data');
    $carton = collect($product['variants'][0]['units'])->firstWhere('code', 'carton');

    loginAs($admin)->patchJson("/api/v1/variant-units/{$carton['id']}", ['factor' => 10])->assertOk()->assertJsonPath('data.factor', 10);

    actingAsCompany($company);
    app(VariantCatalog::class)->lockUnit($carton['id']);

    loginAs($admin)->patchJson("/api/v1/variant-units/{$carton['id']}", ['factor' => 24])->assertStatus(409)->assertJsonPath('code', 'unit_locked');
    loginAs($admin)->patchJson("/api/v1/variant-units/{$carton['id']}", ['label' => 'carton de 10'])->assertOk();
});

it('gère des prix datés sans chevauchement et clôt le prix ouvert précédent', function () {
    [, $admin] = catalogAdmin();
    $product = loginAs($admin)->postJson('/api/v1/products', ['reference' => 'P', 'name' => 'P'])->json('data');
    $variant = $product['variants'][0]['id'];
    $piece = $product['variants'][0]['units'][0]['id'];
    $retail = collect(loginAs($admin)->getJson('/api/v1/price-lists')->json('data'))->firstWhere('code', 'retail')['id'];
    $post = fn (array $body) => loginAs($admin)->postJson("/api/v1/product-variants/{$variant}/prices", $body + ['price_list_id' => $retail, 'unit_id' => $piece]);

    $post(['price' => '1000', 'valid_from' => '2026-01-01'])->assertCreated()->assertJsonPath('data.price', ['amount' => '1000', 'currency' => 'XOF']);
    $post(['price' => '1200', 'valid_from' => '2026-06-01'])->assertCreated();
    $post(['price' => '1100', 'valid_from' => '2026-03-01', 'valid_to' => '2026-07-01'])->assertStatus(422)->assertJsonPath('errors.0.code', 'price_overlap');
    $post(['price' => '1000.5', 'valid_from' => '2027-01-01'])->assertStatus(422)->assertJsonPath('errors.0.pointer', '/price');

    $prices = collect(loginAs($admin)->getJson("/api/v1/product-variants/{$variant}/prices")->json('data'))->sortBy('valid_from')->values();
    expect($prices[0]['valid_to'])->toBe('2026-05-31')->and($prices[1]['valid_to'])->toBeNull();
});

it('propose le prix en vigueur, et à défaut de prix carton : prix pièce × facteur (CAT-004)', function () {
    [, $admin] = catalogAdmin();
    $product = loginAs($admin)->postJson('/api/v1/products', ['reference' => 'Q', 'name' => 'Q', 'pieces_per_carton' => 6])->json('data');
    $variant = $product['variants'][0];
    [$piece, $carton] = [collect($variant['units'])->firstWhere('code', 'piece')['id'], collect($variant['units'])->firstWhere('code', 'carton')['id']];
    $retail = collect(loginAs($admin)->getJson('/api/v1/price-lists')->json('data'))->firstWhere('code', 'retail')['id'];
    loginAs($admin)->postJson("/api/v1/product-variants/{$variant['id']}/prices", ['price_list_id' => $retail, 'unit_id' => $piece, 'price' => '1500', 'valid_from' => '2026-01-01'])->assertCreated();

    loginAs($admin)->getJson("/api/v1/pricing/quote?variant_id={$variant['id']}&unit_id={$carton}&date=2026-11-05")
        ->assertOk()->assertJsonPath('data.price', ['amount' => '9000', 'currency' => 'XOF'])->assertJsonPath('data.source', 'derived');

    loginAs($admin)->postJson("/api/v1/product-variants/{$variant['id']}/prices", ['price_list_id' => $retail, 'unit_id' => $carton, 'price' => '8500', 'valid_from' => '2026-11-01'])->assertCreated();
    loginAs($admin)->getJson("/api/v1/pricing/quote?variant_id={$variant['id']}&unit_id={$carton}&date=2026-11-05")
        ->assertJsonPath('data.price.amount', '8500')->assertJsonPath('data.source', 'list');

    loginAs($admin)->getJson("/api/v1/pricing/quote?variant_id={$variant['id']}&unit_id={$piece}&date=2025-12-31")->assertNotFound();
});

it('retrouve une variante et son conditionnement par code-barres', function () {
    [, $admin] = catalogAdmin();
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'BC', 'name' => 'Code', 'pieces_per_carton' => 4, 'carton_barcode' => '999000'])->assertCreated();

    loginAs($admin)->getJson('/api/v1/product-variants/lookup?barcode=999000')
        ->assertOk()->assertJsonPath('data.unit.code', 'carton')->assertJsonPath('data.unit.factor', 4)->assertJsonPath('data.product.reference', 'BC');
    loginAs($admin)->getJson('/api/v1/product-variants/lookup?barcode=inconnu')->assertNotFound();
});

it('recherche par nom, référence, SKU ou code-barres et filtre par catégorie, sous-catégories comprises', function () {
    [, $admin] = catalogAdmin();
    $parent = loginAs($admin)->postJson('/api/v1/categories', ['name' => 'Textile'])->json('data.id');
    $child = loginAs($admin)->postJson('/api/v1/categories', ['name' => 'Enfants', 'parent_id' => $parent])->json('data.id');
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'E1', 'name' => 'Pyjama enfant', 'category_id' => $child, 'barcode' => '42424242'])->assertCreated();
    loginAs($admin)->postJson('/api/v1/products', ['reference' => 'X1', 'name' => 'Casserole'])->assertCreated();

    expect(loginAs($admin)->getJson('/api/v1/products?q=pyjama')->json('meta.total'))->toBe(1)
        ->and(loginAs($admin)->getJson('/api/v1/products?q=42424242')->json('data.0.reference'))->toBe('E1')
        ->and(loginAs($admin)->getJson("/api/v1/products?filter[category_id]={$parent}")->json('meta.total'))->toBe(1);
});

it('gère l\'arborescence : nom unique par parent, pas de cycle, suppression d\'une catégorie vide seulement', function () {
    [, $admin] = catalogAdmin();
    $a = loginAs($admin)->postJson('/api/v1/categories', ['name' => 'A'])->json('data.id');
    $b = loginAs($admin)->postJson('/api/v1/categories', ['name' => 'B', 'parent_id' => $a])->json('data');
    loginAs($admin)->postJson('/api/v1/categories', ['name' => 'B', 'parent_id' => $a])->assertStatus(422);
    loginAs($admin)->postJson('/api/v1/categories', ['name' => 'B'])->assertCreated();

    loginAs($admin)->patchJson("/api/v1/categories/{$a}", ['parent_id' => $b['id']])->assertStatus(422)->assertJsonPath('errors.0.pointer', '/parent_id');
    loginAs($admin)->deleteJson("/api/v1/categories/{$a}")->assertStatus(409)->assertJsonPath('code', 'category_not_empty');
    loginAs($admin)->deleteJson("/api/v1/categories/{$b['id']}")->assertNoContent();
});

it('réencode les photos en WebP (métadonnées supprimées) avec une miniature', function () {
    Storage::fake('private');
    [, $admin] = catalogAdmin();
    $product = loginAs($admin)->postJson('/api/v1/products', ['reference' => 'IMG', 'name' => 'Photo'])->json('data.id');

    $image = loginAs($admin)->post("/api/v1/products/{$product}/images", ['image' => UploadedFile::fake()->image('photo.jpg', 2400, 1200)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    $files = DB::table('files')->orderBy('created_at')->get();
    expect($files)->toHaveCount(2)->and($files->pluck('mime_type')->unique()->all())->toBe(['image/webp']);

    $thumb = loginAs($admin)->get($image['thumbnail_url']);
    $thumb->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect(getimagesizefromstring($thumb->streamedContent())[0])->toBe(320);

    expect(loginAs($admin)->getJson("/api/v1/products/{$product}")->json('data.thumbnail_url'))->toBe($image['thumbnail_url']);
    loginAs($admin)->deleteJson("/api/v1/product-images/{$image['id']}")->assertNoContent();
    expect(DB::table('files')->count())->toBe(0);
});

it('réserve la création de produits aux rôles autorisés', function () {
    $company = provisionedCompany();

    loginAs(userWithRole($company, RoleTemplate::Seller))->postJson('/api/v1/products', ['reference' => 'S', 'name' => 'S'])->assertForbidden();
    loginAs(userWithRole($company, RoleTemplate::Storekeeper))->postJson('/api/v1/products', ['reference' => 'S', 'name' => 'S'])->assertCreated();
});
