<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** Dépôts de l'entreprise active, pour les modules qui y font entrer du stock. */
interface WarehouseDirectory
{
    public function isActive(string $warehouseId): bool;

    /** @return array{id: string, code: string, name: string}|null */
    public function summary(string $warehouseId): ?array;
}
