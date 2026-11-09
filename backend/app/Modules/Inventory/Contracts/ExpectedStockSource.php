<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * Fournisseur de stock attendu (ST-001) : marchandises commandées ou en route, visibles mais jamais vendables.
 * Implémenté par les modules amont (arrivages) et étiqueté TAG.
 */
interface ExpectedStockSource
{
    public const TAG = 'inventory.expected_stock';

    /**
     * Quantités attendues par « dépôt|variante ».
     *
     * @param  list<string>  $variantIds
     * @return array<string, int>
     */
    public function expected(array $variantIds): array;
}
