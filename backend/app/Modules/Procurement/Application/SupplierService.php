<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;

/** Fournisseurs (L3-01) : marchandises, transitaires, transporteurs, douane… avec leur devise de facturation. */
final class SupplierService
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $actorId): Supplier
    {
        $this->assertCodeFree((string) $data['code'], null);

        $supplier = new Supplier;
        $supplier->fill($data + ['status' => 'active'])->forceFill(['created_by' => $actorId])->save();

        return $supplier->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data, ?string $actorId): Supplier
    {
        return $this->db->transaction(function () use ($id, $data, $actorId) {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['code'])) {
                $this->assertCodeFree((string) $data['code'], $supplier->id);
            }

            // La devise porte les engagements existants : elle est figée dès le premier document (PUR-002).
            if (isset($data['currency']) && $data['currency'] !== $supplier->currency && $supplier->has_history) {
                throw BusinessRuleViolation::invalidField('currency', 'La devise d\'un fournisseur ayant des documents ne peut plus changer.', 'locked');
            }

            $supplier->fill($data)->forceFill(['updated_by' => $actorId])->save();

            return $supplier;
        });
    }

    private function assertCodeFree(string $code, ?string $exceptId): void
    {
        $query = Supplier::query()->where('code', $code);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw BusinessRuleViolation::invalidField('code', 'Ce code fournisseur est déjà utilisé.', 'duplicate');
        }
    }
}
