<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Contracts;

/** Accès de la logistique aux commandes d'achat de l'entreprise active. */
interface PurchaseOrderBook
{
    /** @return array{id: string, number: string, supplier_id: string, currency: string, status: string, estimated_rate: string|null}|null */
    public function summary(string $purchaseOrderId): ?array;

    /**
     * Lignes d'une commande confirmée ou partiellement reçue ; refus métier sinon.
     *
     * @return list<PurchaseOrderLineSummary>
     */
    public function receivableLines(string $purchaseOrderId): array;

    /**
     * @param  list<string>  $lineIds
     * @return array<string, PurchaseOrderLineSummary>
     */
    public function lines(array $lineIds): array;

    /**
     * Enregistre les quantités reçues (unités de base) et met à jour l'état des commandes (PUR-005).
     *
     * @param  array<string, int>  $quantities  ligne de BC → quantité reçue
     */
    public function recordReceipt(array $quantities): void;
}
