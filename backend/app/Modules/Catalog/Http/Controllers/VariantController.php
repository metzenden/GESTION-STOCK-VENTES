<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\PriceService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Http\Resources\CatalogPresenter;
use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductPrice;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Variantes, conditionnements, codes-barres et prix (docs/05 §2.3). */
final class VariantController
{
    public function __construct(private readonly ProductService $products) {}

    public function index(string $product): JsonResponse
    {
        $variants = ProductVariant::query()->where('product_id', Product::query()->findOrFail($product)->id)
            ->with(['units', 'attributeValues'])->orderByDesc('is_default')->orderBy('sku')->get();

        return new JsonResponse(['data' => $variants->map(CatalogPresenter::variant(...))->values()]);
    }

    public function store(Request $request, string $product): JsonResponse
    {
        $productId = Product::query()->findOrFail($product)->id;
        $validated = $request->validate(ProductController::variantRules(''));
        $variant = $this->products->addVariant($productId, $validated, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::variant($variant->load(['units', 'attributeValues']))], 201);
    }

    public function update(Request $request, string $variant): JsonResponse
    {
        $variant = ProductVariant::query()->findOrFail($variant)->id;
        $validated = $request->validate([
            'sku' => ['sometimes', 'string', 'max:64'],
            'label' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', 'in:active,inactive'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $updated = $this->products->updateVariant($variant, $validated, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::variant($updated->load(['units', 'attributeValues']))]);
    }

    public function units(string $variant): JsonResponse
    {
        $units = VariantUnit::query()->where('variant_id', ProductVariant::query()->findOrFail($variant)->id)->orderBy('factor')->get();

        return new JsonResponse(['data' => $units->map(CatalogPresenter::unit(...))->values()]);
    }

    public function storeUnit(Request $request, string $variant): JsonResponse
    {
        $variant = ProductVariant::query()->findOrFail($variant)->id;
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{1,16}$/'],
            'label' => ['required', 'string', 'max:32'],
            'factor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_sellable' => ['sometimes', 'boolean'],
        ]);

        $unit = $this->products->addUnit($variant, $validated, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::unit($unit)], 201);
    }

    public function updateUnit(Request $request, string $unit): JsonResponse
    {
        $unit = VariantUnit::query()->findOrFail($unit)->id;
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:32'],
            'factor' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_sellable' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse(['data' => CatalogPresenter::unit($this->products->updateUnit(
            $unit, $validated, $request->user()?->getAuthIdentifier(),
        ))]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['barcode' => ['required', 'string', 'max:64']]);
        $unit = VariantUnit::query()->where('barcode', $validated['barcode'])->firstOrFail();
        $variant = ProductVariant::query()->with(['product', 'units', 'attributeValues'])->findOrFail($unit->variant_id);
        $product = $variant->product ?? throw (new ModelNotFoundException)->setModel(ProductVariant::class);

        return new JsonResponse(['data' => [
            'variant' => CatalogPresenter::variant($variant),
            'unit' => CatalogPresenter::unit($unit),
            'product' => ['id' => $product->id, 'reference' => $product->reference, 'name' => $product->name, 'status' => $product->status->value],
        ]]);
    }

    public function prices(string $variant): JsonResponse
    {
        $id = ProductVariant::query()->findOrFail($variant)->id;
        $currencies = PriceList::query()->pluck('currency', 'id');
        $prices = ProductPrice::query()->where('variant_id', $id)->orderBy('price_list_id')->orderBy('unit_id')->orderByDesc('valid_from')->get();

        return new JsonResponse(['data' => $prices->map(fn (ProductPrice $p) => CatalogPresenter::price($p, (string) $currencies[$p->price_list_id]))->values()]);
    }

    public function storePrice(Request $request, string $variant, PriceService $prices): JsonResponse
    {
        $variant = ProductVariant::query()->findOrFail($variant)->id;
        $validated = $request->validate([
            'price_list_id' => ['required', 'string', 'size:26'],
            'unit_id' => ['required', 'string', 'size:26'],
            'price' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $price = $prices->add(
            $variant,
            $validated['price_list_id'],
            $validated['unit_id'],
            $validated['price'],
            CarbonImmutable::parse($validated['valid_from']),
            isset($validated['valid_to']) ? CarbonImmutable::parse($validated['valid_to']) : null,
            $request->user()?->getAuthIdentifier(),
        );

        return new JsonResponse(['data' => CatalogPresenter::price($price, PriceList::query()->findOrFail($price->price_list_id)->currency)], 201);
    }
}
