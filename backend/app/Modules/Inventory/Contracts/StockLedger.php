<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use DateTimeInterface;

/**
 * Seul point d'écriture du stock (L2-08). Chaque opération s'exécute dans la transaction de l'appelant,
 * verrouille les positions dans l'ordre (dépôt, variante) puis (dépôt, lot) (INV-003, D-056)
 * et échoue sans rien écrire si un invariant serait violé (ST-006, ST-007).
 *
 * Les quantités sont toujours en unités de base (pièces).
 */
interface StockLedger
{
    /** Crée un lot (réception, ouverture, ajustement positif) ; renvoie son identifiant. */
    public function createLot(string $variantId, string $originType, ?string $originId, DateTimeInterface $receivedAt, int $quantity, string $unitCost, string $currency, string $costStatus = 'final', ?string $shipmentId = null): string;

    /** Entrée physique d'un lot dans un dépôt (receipt, return_in, transfer_in, adjustment_in, opening). */
    public function receive(string $warehouseId, string $lotId, int $quantity, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string;

    /** Sortie prélevée sur le disponible d'un lot (adjustment_out, loss). */
    public function issue(string $warehouseId, string $lotId, int $quantity, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string;

    /** Réserve au niveau (dépôt, variante) ; refus complet si le disponible est insuffisant (ST-006). */
    public function reserve(string $warehouseId, string $variantId, int $quantity, string $sourceType, string $sourceId, bool $provisional = false): string;

    /** Libère tout ou partie d'une réservation active (null = tout). */
    public function release(string $sourceType, string $sourceId, ?int $quantity = null): void;

    /**
     * Expédition : le réservé passe « en livraison », affecté aux lots indiqués (ST-004, INV-001).
     *
     * @param  array<string, int>  $allocations  lot → quantité
     */
    public function ship(string $sourceType, string $sourceId, array $allocations): void;

    /**
     * Remise : sortie définitive sale_delivery des lots en livraison (ST-005).
     *
     * @param  array<string, int>  $allocations
     */
    public function deliver(string $warehouseId, string $variantId, array $allocations, StockSource $source, DateTimeInterface $occurredAt): void;

    /**
     * Sortie d'une quantité réservée vers les lots indiqués, avec mouvement (transfert expédié).
     *
     * @param  array<string, int>  $allocations
     */
    public function issueReserved(string $sourceType, string $sourceId, array $allocations, string $movementType, StockSource $source, DateTimeInterface $occurredAt): void;

    /** Bloque une quantité libre d'un lot (endommagé, ajustement en attente…) ; renvoie l'identifiant du blocage. */
    public function hold(string $warehouseId, string $lotId, int $quantity, string $reason, string $sourceType, string $sourceId, ?string $note = null): string;

    public function releaseHold(string $holdId): void;

    /** Sort la quantité bloquée (perte validée) avec un mouvement. */
    public function consumeHold(string $holdId, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string;

    /** Contre-écriture d'un mouvement (INV-004) ; renvoie l'identifiant du mouvement de correction. */
    public function reverse(string $movementId, string $reason, DateTimeInterface $occurredAt): string;

    /**
     * Lots disponibles d'une variante dans un dépôt, ordre FIFO (INV-002).
     *
     * @return array<string, int> lot → quantité affectée
     */
    public function allocateFifo(string $warehouseId, string $variantId, int $quantity): array;

    /**
     * Coût définitif d'un lot provisoire (CR-013) ; renvoie l'ancien coût unitaire. Un lot déjà définitif est refusé (CR-004).
     *
     * @param  array<string, mixed>  $breakdown
     */
    public function finalizeLotCost(string $lotId, string $unitCost, string $calculationId, array $breakdown): string;

    /**
     * Mouvements de ces lots, hors entrée d'origine et transferts internes (sorties déjà valorisées, retours, contre-écritures).
     *
     * @param  list<string>  $lotIds
     * @return list<array{id: string, lot_id: string, movement_type: string, quantity: int, unit_cost: string}>
     */
    public function valuedMovements(array $lotIds): array;

    /**
     * Échec de livraison (DLV-005) : les quantités « en livraison » des lots redeviennent réservées pour l'origine.
     *
     * @param  array<string, int>  $allocations  lot → quantité
     */
    public function failDelivery(string $sourceType, string $sourceId, array $allocations): void;

    /** Coût unitaire moyen des premiers lots disponibles (FIFO) pour une quantité ; null sans stock (alerte de marge, SAL-006). */
    public function estimateCost(string $warehouseId, string $variantId, int $quantity): ?string;

    /**
     * @param  list<string>  $lotIds
     * @return array<string, string> lot → coût unitaire courant
     */
    public function lotCosts(array $lotIds): array;

    /** Réservation provisoire (en attente d'approbation) rendue définitive (APR-005). */
    public function confirmReservation(string $sourceType, string $sourceId): void;

    /** Libère et efface une réservation provisoire jamais consommée (rejet d'approbation) : l'origine pourra réserver à nouveau. */
    public function discardReservation(string $sourceType, string $sourceId): void;
}
