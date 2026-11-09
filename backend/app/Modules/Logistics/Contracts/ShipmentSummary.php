<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

final readonly class ShipmentSummary
{
    public function __construct(
        public string $id,
        public string $number,
        public string $status,
    ) {}

    /** Les entrées du coût de revient restent modifiables jusqu'à la clôture. */
    public function acceptsCosts(): bool
    {
        return ! in_array($this->status, ['closed', 'cancelled'], true);
    }

    public function isFullyReceived(): bool
    {
        return in_array($this->status, ['received', 'closed'], true);
    }
}
