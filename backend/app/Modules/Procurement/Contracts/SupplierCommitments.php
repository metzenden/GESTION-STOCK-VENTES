<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Contracts;

use DateTimeInterface;

/** Engagements fournisseurs créés par d'autres modules (frais d'approche, D-026). */
interface SupplierCommitments
{
    /** Crée un engagement ouvert et renvoie son identifiant. */
    public function commit(string $supplierId, string $currency, string $amount, string $sourceType, string $sourceId, DateTimeInterface $date, ?string $description, ?string $actorId): string;

    /** Compense un engagement par un engagement négatif (annulation de l'origine). */
    public function compensate(string $billId, string $description, ?string $actorId): void;
}
