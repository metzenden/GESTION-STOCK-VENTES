<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\UnitSummary;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Catalog\Contracts\VariantSummary;
use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class EloquentVariantCatalog implements VariantCatalog
{
    public function __construct(
        private readonly ProductService $products,
        private readonly PriceService $prices,
    ) {}

    public function describe(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $result = [];

        foreach (ProductVariant::query()->with('product')->whereKey(array_values(array_unique($variantIds)))->get() as $variant) {
            $product = $variant->product;

            if ($product === null) {
                continue;
            }

            $result[$variant->id] = new VariantSummary(
                $variant->id,
                $product->id,
                $product->reference,
                $product->name,
                $variant->sku,
                $variant->label,
                $product->product_type->value,
                $product->status->value,
                $variant->status,
                $variant->low_stock_threshold,
            );
        }

        return $result;
    }

    public function markHistory(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }

        $productIds = ProductVariant::query()->whereKey($variantIds)->pluck('product_id');
        Product::query()->whereKey($productIds)->where('has_history', false)->update(['has_history' => true]);
    }

    public function lockUnit(string $unitId): void
    {
        VariantUnit::query()->whereKey($unitId)->whereNull('locked_at')->update(['locked_at' => Carbon::now()]);
    }

    public function units(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $result = [];

        foreach (VariantUnit::query()->whereKey(array_values(array_unique($unitIds)))->get() as $unit) {
            $result[$unit->id] = self::unit($unit);
        }

        return $result;
    }

    public function baseUnit(string $variantId): ?UnitSummary
    {
        $unit = VariantUnit::query()->where('variant_id', $variantId)->where('factor', 1)->orderBy('created_at')->first();

        return $unit === null ? null : self::unit($unit);
    }

    public function unitByBarcode(string $barcode): ?UnitSummary
    {
        $unit = VariantUnit::query()->where('barcode', $barcode)->first();

        return $unit === null ? null : self::unit($unit);
    }

    public function matchReference(string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $bySku = ProductVariant::query()->where('sku', $reference)->value('id');

        if (is_string($bySku)) {
            return $bySku;
        }

        $product = Product::query()->where('reference', $reference)->first();

        if ($product !== null) {
            $default = ProductVariant::query()->where('product_id', $product->id)->orderByDesc('is_default')->orderBy('created_at')->value('id');

            if (is_string($default)) {
                return $default;
            }
        }

        return $this->unitByBarcode($reference)?->variantId;
    }

    private static function unit(VariantUnit $unit): UnitSummary
    {
        return new UnitSummary($unit->id, $unit->variant_id, $unit->code, $unit->label, (int) $unit->factor, $unit->barcode);
    }

    public function createStockProduct(string $reference, string $name, ?int $piecesPerCarton, ?string $actorId): string
    {
        $product = $this->products->create(['reference' => $reference, 'name' => $name, 'pieces_per_carton' => $piecesPerCarton], $actorId);

        return (string) ProductVariant::query()->where('product_id', $product->id)->orderByDesc('is_default')->value('id');
    }

    public function priceList(?string $priceListId): ?array
    {
        $list = $priceListId !== null ? PriceList::query()->find($priceListId) : PriceList::query()->where('is_default', true)->first();

        return $list === null ? null : ['id' => $list->id, 'code' => $list->code, 'name' => $list->name, 'currency' => $list->currency];
    }

    public function quote(string $variantId, string $unitId, string $priceListId, \DateTimeInterface $date): ?array
    {
        $quote = $this->prices->quote($variantId, $unitId, $priceListId, CarbonImmutable::instance($date));

        return $quote === null ? null : ['price' => (string) $quote['price']->amount(), 'source' => $quote['source']];
    }
}
