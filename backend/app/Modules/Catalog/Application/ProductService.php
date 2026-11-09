<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Models\AttributeValue;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductPrice;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;

/**
 * Produits, variantes et conditionnements (L2-02, L2-03).
 *
 * - CAT-001 : référence, SKU et code-barres uniques dans l'entreprise.
 * - CAT-002 : tout produit a au moins une variante (variante par défaut créée automatiquement).
 * - CAT-003 : unité de base = pièce (facteur 1) ; tout conditionnement a un facteur entier ≥ 1.
 * - CAT-006 : un produit avec historique ne se supprime pas ; il s'archive.
 * - CAT-007 : le facteur d'un conditionnement utilisé est figé.
 */
final class ProductService
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $actorId): Product
    {
        return $this->db->transaction(function () use ($data, $actorId) {
            $this->assertReferenceFree((string) $data['reference'], null);
            $this->assertCategory($data['category_id'] ?? null);

            /** @var list<array<string, mixed>> $variants */
            $variants = $data['variants'] ?? [];

            $product = new Product;
            $product->fill([
                'reference' => $data['reference'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'product_type' => $data['product_type'] ?? ProductType::Stock->value,
                'base_unit_label' => $data['base_unit_label'] ?? 'pièce',
                'has_variants' => $variants !== [],
                'status' => ProductStatus::Active,
            ])->forceFill(['created_by' => $actorId])->save();

            $synthesized = $variants === [];

            if ($synthesized) {
                $variants = [[
                    'sku' => $data['reference'],
                    'label' => $data['name'],
                    'pieces_per_carton' => $data['pieces_per_carton'] ?? null,
                    'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
                    'barcode' => $data['barcode'] ?? null,
                    'carton_barcode' => $data['carton_barcode'] ?? null,
                ]];
            }

            foreach ($variants as $index => $variant) {
                // Variante par défaut : les erreurs pointent sur les champs du produit lui-même.
                $this->createVariant($product, $variant, $index === 0, $synthesized ? '' : "variants/{$index}/", $actorId);
            }

            return $product;
        });
    }

    /** @param array<string, mixed> $changes */
    public function update(string $productId, array $changes, ?int $expectedVersion, ?string $actorId): Product
    {
        return $this->db->transaction(function () use ($productId, $changes, $expectedVersion, $actorId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            if ($expectedVersion !== null && $expectedVersion !== $product->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            if (isset($changes['reference']) && $changes['reference'] !== $product->reference) {
                $this->assertReferenceFree((string) $changes['reference'], $product->id);
            }

            if (array_key_exists('category_id', $changes)) {
                $this->assertCategory($changes['category_id']);
            }

            if (isset($changes['product_type']) && $changes['product_type'] !== $product->product_type->value && $product->has_history) {
                throw BusinessRuleViolation::invalidField('product_type', 'Le type d\'un produit avec historique ne peut plus changer.');
            }

            $product->fill(array_intersect_key($changes, array_flip(['reference', 'name', 'description', 'category_id', 'product_type', 'base_unit_label'])));

            if ($product->isDirty()) {
                $product->forceFill(['version' => $product->version + 1, 'updated_by' => $actorId])->save();
            }

            return $product;
        });
    }

    public function changeStatus(string $productId, ProductStatus $status, ?string $actorId): Product
    {
        return $this->db->transaction(function () use ($productId, $status, $actorId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            if ($product->status !== $status) {
                $product->forceFill(['status' => $status, 'version' => $product->version + 1, 'updated_by' => $actorId])->save();
            }

            return $product;
        });
    }

    public function delete(string $productId): void
    {
        $this->db->transaction(function () use ($productId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            if ($product->has_history) {
                throw BusinessRuleViolation::hasHistory('Ce produit a un historique (mouvements, achats ou ventes) : archivez-le au lieu de le supprimer.');
            }

            $variantIds = ProductVariant::query()->where('product_id', $product->id)->pluck('id')->all();
            ProductPrice::query()->whereIn('variant_id', $variantIds)->get()->each->delete();
            ProductImage::query()->where('product_id', $product->id)->get()->each->delete();
            $this->db->table('variant_attribute_values')->where('company_id', $product->company_id)->whereIn('variant_id', $variantIds)->delete();
            VariantUnit::query()->whereIn('variant_id', $variantIds)->get()->each->delete();
            ProductVariant::query()->whereIn('id', $variantIds)->get()->each->delete();
            $product->delete();
        });
    }

    /** @param array<string, mixed> $data */
    public function addVariant(string $productId, array $data, ?string $actorId): ProductVariant
    {
        return $this->db->transaction(function () use ($productId, $data, $actorId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variant = $this->createVariant($product, $data, false, '', $actorId);

            if (! $product->has_variants) {
                $product->forceFill(['has_variants' => true, 'version' => $product->version + 1])->save();
            }

            return $variant;
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateVariant(string $variantId, array $changes, ?string $actorId): ProductVariant
    {
        return $this->db->transaction(function () use ($variantId, $changes, $actorId) {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);

            if (isset($changes['sku']) && $changes['sku'] !== $variant->sku) {
                $this->assertSkuFree((string) $changes['sku'], 'sku', $variant->id);
            }

            if (($changes['status'] ?? null) === 'inactive' && $variant->is_default
                && ProductVariant::query()->where('product_id', $variant->product_id)->where('status', 'active')->whereKeyNot($variant->id)->doesntExist()) {
                throw BusinessRuleViolation::invalidField('status', 'Désactivez le produit plutôt que sa seule variante active.');
            }

            $variant->fill(array_intersect_key($changes, array_flip(['sku', 'label', 'status', 'low_stock_threshold'])));
            $variant->forceFill(['updated_by' => $actorId])->save();

            return $variant;
        });
    }

    /** @param array{code: string, label: string, factor: int, barcode?: string|null, is_sellable?: bool} $data */
    public function addUnit(string $variantId, array $data, ?string $actorId): VariantUnit
    {
        return $this->db->transaction(function () use ($variantId, $data, $actorId) {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);

            if (VariantUnit::query()->where('variant_id', $variant->id)->where('code', $data['code'])->exists()) {
                throw BusinessRuleViolation::invalidField('code', 'Ce conditionnement existe déjà pour la variante.');
            }

            return $this->createUnit($variant, $data['code'], $data['label'], $data['factor'], $data['barcode'] ?? null, $data['is_sellable'] ?? true, 'barcode', $actorId);
        });
    }

    /** @param array{label?: string, factor?: int, barcode?: string|null, is_sellable?: bool} $changes */
    public function updateUnit(string $unitId, array $changes, ?string $actorId): VariantUnit
    {
        return $this->db->transaction(function () use ($unitId, $changes, $actorId) {
            $unit = VariantUnit::query()->lockForUpdate()->findOrFail($unitId);

            if (isset($changes['factor']) && $changes['factor'] !== $unit->factor) {
                if ($unit->locked_at !== null) {
                    throw new BusinessRuleViolation('unit_locked', 'Conditionnement figé', 'Ce conditionnement a déjà été utilisé : son facteur ne peut plus changer. Créez un nouveau conditionnement.', 409);
                }

                if ($unit->code === 'piece') {
                    throw BusinessRuleViolation::invalidField('factor', 'L\'unité de base vaut toujours 1.');
                }
            }

            if (array_key_exists('barcode', $changes) && $changes['barcode'] !== null && $changes['barcode'] !== $unit->barcode) {
                $this->assertBarcodeFree($changes['barcode'], 'barcode', $unit->id);
            }

            $unit->fill(array_intersect_key($changes, array_flip(['label', 'factor', 'barcode', 'is_sellable'])));
            $unit->forceFill(['updated_by' => $actorId])->save();

            if ($unit->code === 'carton') {
                ProductVariant::query()->whereKey($unit->variant_id)->update(['pieces_per_carton' => $unit->factor]);
            }

            return $unit;
        });
    }

    /** @param array<string, mixed> $data */
    private function createVariant(Product $product, array $data, bool $isDefault, string $pointer, ?string $actorId): ProductVariant
    {
        $this->assertSkuFree((string) $data['sku'], $pointer.'sku', null);
        $ppc = isset($data['pieces_per_carton']) ? (int) $data['pieces_per_carton'] : null;

        $variant = new ProductVariant;
        $variant->fill([
            'product_id' => $product->id,
            'sku' => $data['sku'],
            'label' => $data['label'] ?? $product->name,
            'is_default' => $isDefault,
            'pieces_per_carton' => $ppc,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'status' => 'active',
        ])->forceFill(['created_by' => $actorId])->save();

        /** @var list<string> $valueIds */
        $valueIds = $data['attribute_value_ids'] ?? [];

        if ($valueIds !== []) {
            $found = AttributeValue::query()->whereKey($valueIds)->count();

            if ($found !== count(array_unique($valueIds))) {
                throw BusinessRuleViolation::invalidField($pointer.'attribute_value_ids', 'Une valeur d\'attribut n\'existe pas.');
            }

            $variant->attributeValues()->attach(array_fill_keys(array_unique($valueIds), ['company_id' => $product->company_id]));
        }

        $this->createUnit($variant, 'piece', $product->base_unit_label, 1, $data['barcode'] ?? null, true, $pointer.'barcode', $actorId);

        if ($ppc !== null && $ppc > 1) {
            $this->createUnit($variant, 'carton', 'carton', $ppc, $data['carton_barcode'] ?? null, true, $pointer.'carton_barcode', $actorId);
        }

        return $variant;
    }

    private function createUnit(ProductVariant $variant, string $code, string $label, int $factor, ?string $barcode, bool $sellable, string $pointer, ?string $actorId): VariantUnit
    {
        if ($barcode !== null) {
            $this->assertBarcodeFree($barcode, $pointer, null);
        }

        $unit = new VariantUnit;
        $unit->fill([
            'variant_id' => $variant->id,
            'code' => $code,
            'label' => $label,
            'factor' => $factor,
            'barcode' => $barcode,
            'is_sellable' => $sellable,
        ])->forceFill(['created_by' => $actorId])->save();

        return $unit;
    }

    private function assertReferenceFree(string $reference, ?string $exceptId): void
    {
        if (Product::query()->where('reference', $reference)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw BusinessRuleViolation::invalidField('reference', 'Cette référence est déjà utilisée par un autre produit.');
        }
    }

    private function assertSkuFree(string $sku, string $pointer, ?string $exceptId): void
    {
        if (ProductVariant::query()->where('sku', $sku)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw BusinessRuleViolation::invalidField($pointer, "Le SKU « {$sku} » est déjà utilisé.");
        }
    }

    private function assertBarcodeFree(string $barcode, string $pointer, ?string $exceptId): void
    {
        if (VariantUnit::query()->where('barcode', $barcode)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw BusinessRuleViolation::invalidField($pointer, "Le code-barres « {$barcode} » est déjà attribué.");
        }
    }

    private function assertCategory(mixed $categoryId): void
    {
        if ($categoryId !== null && Category::query()->whereKey($categoryId)->doesntExist()) {
            throw BusinessRuleViolation::invalidField('category_id', 'Cette catégorie n\'existe pas.');
        }
    }
}
