<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

/** Ligne d'arrivage vue par le calcul du coût de revient (docs/04 §7.1). */
final readonly class CostingLine
{
    /**
     * @param  list<string>  $lotIds  lots nés des réceptions validées de cette ligne
     */
    public function __construct(
        public string $id,
        public int $position,
        public string $variantId,
        public ?string $supplierId,
        public int $qtyExpected,
        /** Accepté + endommagé conservé, sur les réceptions validées (CR-010, CR-011). */
        public int $qtyStocked,
        public ?int $piecesPerCarton,
        public ?string $cbmTotal,
        public string $purchaseCurrency,
        public string $purchaseUnitPrice,
        public ?string $estimatedRate,
        public array $lotIds,
    ) {}
}
