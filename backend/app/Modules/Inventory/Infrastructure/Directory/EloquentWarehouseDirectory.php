<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Directory;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;

final class EloquentWarehouseDirectory implements WarehouseDirectory
{
    public function isActive(string $warehouseId): bool
    {
        return Warehouse::query()->whereKey($warehouseId)->where('status', 'active')->exists();
    }

    public function summary(string $warehouseId): ?array
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        return $warehouse === null ? null : ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name];
    }
}
