<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

final readonly class VariantSummary
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $reference,
        public string $productName,
        public string $sku,
        public string $label,
        public string $productType,
        public string $productStatus,
        public string $variantStatus,
        public ?int $lowStockThreshold,
    ) {}

    public function isStocked(): bool
    {
        return $this->productType === 'stock';
    }

    /** @return array{id: string, product_id: string, reference: string, product_name: string, sku: string, label: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'reference' => $this->reference,
            'product_name' => $this->productName,
            'sku' => $this->sku,
            'label' => $this->label,
        ];
    }
}
