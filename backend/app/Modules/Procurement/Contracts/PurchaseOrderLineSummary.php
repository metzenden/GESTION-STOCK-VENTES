<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Contracts;

/** Ligne de commande d'achat vue par la logistique (quantités en unités de base). */
final readonly class PurchaseOrderLineSummary
{
    public function __construct(
        public string $id,
        public string $purchaseOrderId,
        public string $purchaseOrderNumber,
        public string $supplierId,
        public string $variantId,
        public int $unitFactor,
        public int $qtyOrdered,
        public int $qtyReceived,
        /** Prix d'achat par pièce, devise du BC, 8 décimales. */
        public string $unitPricePerPiece,
        public string $currency,
    ) {}

    public function remaining(): int
    {
        return max($this->qtyOrdered - $this->qtyReceived, 0);
    }
}
