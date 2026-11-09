<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

/** Condition de clôture d'un arrivage : coût de revient définitif (docs/04 §6). Implémenté par Costing. */
interface ShipmentCloseGuard
{
    public function canClose(string $shipmentId): bool;
}
