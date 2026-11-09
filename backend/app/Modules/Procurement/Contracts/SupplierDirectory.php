<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Contracts;

interface SupplierDirectory
{
    public function find(string $supplierId): ?SupplierSummary;

    /**
     * @param  list<string>  $supplierIds
     * @return array<string, SupplierSummary>
     */
    public function describe(array $supplierIds): array;

    /** Le fournisseur a un historique : il ne peut plus être supprimé (GEN-002). */
    public function markHistory(string $supplierId): void;

    /** @param array<string, mixed> $data  Création (import initial) ; renvoie l'identifiant. */
    public function create(array $data, ?string $actorId): string;
}
