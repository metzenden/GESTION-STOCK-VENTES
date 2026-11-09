<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

/**
 * Coût unitaire d'un lot créé à la réception (CR-013), en devise de référence.
 * Implémenté par le module Costing : achat converti au taux estimé + frais d'approche estimés.
 */
interface ProvisionalCostEstimator
{
    /**
     * @param  list<ReceivedLine>  $lines
     * @return array<string, array{unit_cost: string, currency: string, cost_status: string}> ligne de réception → coût
     */
    public function estimate(array $lines): array;
}
