<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

/** Ligne de réception à valoriser (prix d'achat par pièce dans la devise d'achat). */
final readonly class ReceivedLine
{
    public function __construct(
        public string $receiptLineId,
        public ?string $shipmentId,
        public ?string $shipmentLineId,
        public string $variantId,
        public int $quantity,
        public string $purchaseCurrency,
        public string $purchaseUnitPrice,
        public ?string $estimatedRate,
    ) {}
}
