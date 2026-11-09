<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use DateTimeInterface;

/**
 * Grand livre financier (L5a-02) : solde = Σ mouvements (FIN-001), mouvements en ajout seul,
 * solde négatif refusé sauf autorisation du compte (FIN-003), contrôlé sous verrou du compte.
 */
interface FinancialLedger
{
    /** Écrit un mouvement ; renvoie son identifiant. $amount est toujours positif. */
    public function post(string $accountId, string $movementType, string $direction, string $amount, string $sourceType, string $sourceId, string $description, DateTimeInterface $occurredAt): string;

    /** Contre-écriture d'un mouvement (sens inverse, type reversal) ; renvoie l'identifiant du mouvement correctif. */
    public function reverse(string $movementId, string $reason, DateTimeInterface $occurredAt): string;

    public function account(string $accountId): ?AccountSummary;
}
