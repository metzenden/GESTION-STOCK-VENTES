<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dépôts (L2-06). Un dépôt n'est jamais supprimé : il se désactive. */
final class WarehouseController
{
    public function __construct(private readonly CompanyPreferences $preferences) {}

    public function index(): JsonResponse
    {
        $default = $this->preferences->defaultSalesWarehouseId();

        return new JsonResponse(['data' => Warehouse::query()->orderBy('name')->get()->map(fn (Warehouse $w) => self::present($w, $default))->values()]);
    }

    public function store(Request $request, ConnectionInterface $db): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,16}$/'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $warehouse = $db->transaction(function () use ($validated, $request) {
            $this->assertUnique($validated['code'], $validated['name'], null);
            $warehouse = new Warehouse(['code' => $validated['code'], 'name' => $validated['name'], 'address' => $validated['address'] ?? null, 'status' => 'active']);
            $warehouse->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

            if (($validated['is_default'] ?? false) === true) {
                $this->preferences->setDefaultSalesWarehouse($warehouse->id);
            }

            return $warehouse;
        });

        return new JsonResponse(['data' => self::present($warehouse, $this->preferences->defaultSalesWarehouseId())], 201);
    }

    public function update(Request $request, string $warehouse, ConnectionInterface $db): JsonResponse
    {
        $row = Warehouse::query()->findOrFail($warehouse);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            'is_default' => ['sometimes', 'accepted'],
        ]);

        $db->transaction(function () use ($row, $validated, $request) {
            if (isset($validated['name']) && $validated['name'] !== $row->name) {
                $this->assertUnique(null, $validated['name'], $row->id);
            }

            if (($validated['status'] ?? null) === 'inactive' && $this->preferences->defaultSalesWarehouseId() === $row->id) {
                throw BusinessRuleViolation::invalidField('status', 'Choisissez d\'abord un autre dépôt par défaut.');
            }

            $row->fill(array_intersect_key($validated, array_flip(['name', 'address', 'status'])));
            $row->forceFill(['updated_by' => $request->user()?->getAuthIdentifier()])->save();

            if (isset($validated['is_default'])) {
                if ($row->status !== 'active') {
                    throw BusinessRuleViolation::invalidField('is_default', 'Un dépôt inactif ne peut pas être le dépôt par défaut.');
                }

                $this->preferences->setDefaultSalesWarehouse($row->id);
            }
        });

        return new JsonResponse(['data' => self::present($row, $this->preferences->defaultSalesWarehouseId())]);
    }

    /** @return array<string, mixed> */
    private static function present(Warehouse $warehouse, ?string $defaultId): array
    {
        return [
            'id' => $warehouse->id,
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'status' => $warehouse->status,
            'is_default' => $warehouse->id === $defaultId,
        ];
    }

    private function assertUnique(?string $code, string $name, ?string $exceptId): void
    {
        if ($code !== null && Warehouse::query()->where('code', $code)->exists()) {
            throw BusinessRuleViolation::invalidField('code', 'Ce code de dépôt existe déjà.');
        }

        if (Warehouse::query()->where('name', $name)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw BusinessRuleViolation::invalidField('name', 'Un dépôt porte déjà ce nom.');
        }
    }
}
