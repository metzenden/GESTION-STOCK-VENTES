<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** Conditionnement d'une variante (pièce, carton…), vu par les autres modules. */
final readonly class UnitSummary
{
    public function __construct(
        public string $id,
        public string $variantId,
        public string $code,
        public string $label,
        public int $factor,
        public ?string $barcode,
    ) {}

    /** @return array{id: string, variant_id: string, code: string, label: string, factor: int} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'variant_id' => $this->variantId, 'code' => $this->code, 'label' => $this->label, 'factor' => $this->factor];
    }
}
