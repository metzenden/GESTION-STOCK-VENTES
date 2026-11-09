<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Directory;

use App\Modules\Procurement\Application\SupplierService;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Procurement\Contracts\SupplierSummary;
use App\Modules\Procurement\Infrastructure\Models\Supplier;

final class EloquentSupplierDirectory implements SupplierDirectory
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function find(string $supplierId): ?SupplierSummary
    {
        return $this->describe([$supplierId])[$supplierId] ?? null;
    }

    public function describe(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $result = [];

        foreach (Supplier::query()->whereKey(array_values(array_unique($supplierIds)))->get() as $s) {
            $result[$s->id] = new SupplierSummary($s->id, $s->code, $s->name, $s->supplier_type, $s->currency, $s->status);
        }

        return $result;
    }

    public function markHistory(string $supplierId): void
    {
        Supplier::query()->whereKey($supplierId)->where('has_history', false)->update(['has_history' => true]);
    }

    public function create(array $data, ?string $actorId): string
    {
        return $this->suppliers->create($data, $actorId)->id;
    }
}
