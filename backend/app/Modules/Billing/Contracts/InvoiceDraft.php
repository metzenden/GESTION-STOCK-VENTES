<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use DateTimeInterface;

/** Contenu d'une facture à émettre à la confirmation d'une commande (SAL-002). */
final readonly class InvoiceDraft
{
    /**
     * @param  array<string, mixed>  $customerSnapshot
     * @param  list<DocumentLine>  $lines
     */
    public function __construct(
        public string $salesOrderId,
        public string $customerId,
        public array $customerSnapshot,
        public string $currency,
        public DateTimeInterface $issueDate,
        public int $paymentTermsDays,
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $total,
    ) {}
}
