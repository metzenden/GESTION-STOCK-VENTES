<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

final readonly class InvoiceSummary
{
    public function __construct(
        public string $id,
        public string $number,
        public string $salesOrderId,
        public string $customerId,
        public string $currency,
        public string $total,
        public string $amountPaid,
        public string $amountCredited,
        public string $balanceDue,
        public ?string $paymentMethod,
        public string $paymentStatus,
        public string $status,
    ) {}
}
