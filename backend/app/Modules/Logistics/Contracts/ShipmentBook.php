<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Contracts;

/** Lecture des arrivages par le module Costing. */
interface ShipmentBook
{
    public function find(string $shipmentId): ?ShipmentSummary;

    /** Verrouille l'arrivage le temps d'une transaction (calcul, finalisation). */
    public function lock(string $shipmentId): ShipmentSummary;

    /** @return list<CostingLine> */
    public function costingLines(string $shipmentId): array;

    /** La ligne appartient-elle à l'arrivage ? */
    public function hasLine(string $shipmentId, string $lineId): bool;
}
