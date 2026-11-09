<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

/** Ligne figée d'une facture ou d'un avoir (montants dans la devise du document). */
final readonly class DocumentLine
{
    public function __construct(
        public ?string $salesOrderLineId,
        public string $description,
        public string $unitLabel,
        public int $qtyUnits,
        public string $unitPrice,
        public string $discountAmount,
        public string $taxRate,
        public string $taxAmount,
        public string $lineTotal,
        /** Quantité en unités de base (avoirs de retour ou d'annulation partielle). */
        public ?int $qtyBase = null,
    ) {}
}
