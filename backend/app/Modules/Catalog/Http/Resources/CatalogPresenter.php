<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductPrice;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use App\Modules\Shared\Domain\ValueObjects\Money;
use App\Modules\Shared\Infrastructure\Money\CurrencyRegistry;

/** Représentations JSON du catalogue (docs/05 §1.3 : montants en objets { amount, currency }). */
final class CatalogPresenter
{
    /** @return array<string, mixed> */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'path' => $category->path,
            'is_active' => $category->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $product, bool $withVariants = true): array
    {
        $data = [
            'id' => $product->id,
            'reference' => $product->reference,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category !== null ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
            'product_type' => $product->product_type->value,
            'base_unit_label' => $product->base_unit_label,
            'has_variants' => $product->has_variants,
            'status' => $product->status->value,
            'has_history' => $product->has_history,
            'version' => $product->version,
            'thumbnail_url' => ($first = $product->images->first()) !== null ? self::imageUrl($first, true) : null,
        ];

        if ($withVariants) {
            $data['variants'] = $product->variants->map(self::variant(...))->values()->all();
            $data['images'] = $product->images->map(self::image(...))->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function variant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'label' => $variant->label,
            'is_default' => $variant->is_default,
            'pieces_per_carton' => $variant->pieces_per_carton,
            'low_stock_threshold' => $variant->low_stock_threshold,
            'status' => $variant->status,
            'attribute_value_ids' => $variant->attributeValues->pluck('id')->values()->all(),
            'units' => $variant->units->map(self::unit(...))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function unit(VariantUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'variant_id' => $unit->variant_id,
            'code' => $unit->code,
            'label' => $unit->label,
            'factor' => $unit->factor,
            'barcode' => $unit->barcode,
            'is_sellable' => $unit->is_sellable,
            'locked' => $unit->locked_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function image(ProductImage $image): array
    {
        return [
            'id' => $image->id,
            'variant_id' => $image->variant_id,
            'position' => $image->position,
            'url' => self::imageUrl($image, false),
            'thumbnail_url' => self::imageUrl($image, true),
        ];
    }

    /** @return array<string, mixed> */
    public static function priceList(PriceList $list): array
    {
        return ['id' => $list->id, 'code' => $list->code, 'name' => $list->name, 'currency' => $list->currency, 'is_default' => $list->is_default];
    }

    /** @return array<string, mixed> */
    public static function price(ProductPrice $price, string $currency): array
    {
        return [
            'id' => $price->id,
            'variant_id' => $price->variant_id,
            'price_list_id' => $price->price_list_id,
            'unit_id' => $price->unit_id,
            'price' => Money::of((string) $price->price, app(CurrencyRegistry::class)->of($currency))->rounded()->jsonSerialize(),
            'valid_from' => $price->valid_from->format('Y-m-d'),
            'valid_to' => $price->valid_to?->format('Y-m-d'),
        ];
    }

    private static function imageUrl(ProductImage $image, bool $thumbnail): string
    {
        return "/api/v1/product-images/{$image->id}/".($thumbnail ? 'thumbnail' : 'file');
    }
}
