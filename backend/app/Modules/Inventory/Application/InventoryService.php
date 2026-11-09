<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Infrastructure\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Models\InventoryLine;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Inventaires (L2-12) : brouillon → comptage (snapshot) → revue ⇄ comptage → validé (ajustement) ; annulation.
 *
 * - INV-101 : au démarrage, les quantités théoriques par lot sont figées.
 * - INV-102 : les variantes restent vendables ; écart = compté − (théorique + mouvements depuis le snapshot).
 * - INV-103 : la validation produit un seul ajustement, soumis aux règles d'approbation.
 * - INV-105 : un seul inventaire ouvert par (dépôt, variante).
 */
final class InventoryService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly AdjustmentService $adjustments,
        private readonly VariantCatalog $catalog,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<string> $variantIds vide = inventaire complet du dépôt */
    public function create(string $warehouseId, array $variantIds, ?string $note, ?string $actorId): Inventory
    {
        return $this->db->transaction(function () use ($warehouseId, $variantIds, $note, $actorId) {
            $warehouse = Warehouse::query()->find($warehouseId);

            if ($warehouse === null || $warehouse->status !== 'active') {
                throw BusinessRuleViolation::invalidField('warehouse_id', 'Ce dépôt n\'existe pas ou est inactif.');
            }

            if ($variantIds !== [] && count($this->catalog->describe($variantIds)) !== count(array_unique($variantIds))) {
                throw BusinessRuleViolation::invalidField('variant_ids', 'Une des variantes n\'existe pas.');
            }

            $inventory = new Inventory;
            $inventory->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Inventory, $this->today()),
                'warehouse_id' => $warehouse->id,
                'scope' => $variantIds === [] ? 'full' : 'partial',
                'scope_filter' => $variantIds === [] ? null : ['variant_ids' => array_values(array_unique($variantIds))],
                'status' => 'draft',
                'note' => $note,
            ])->forceFill(['created_by' => $actorId])->save();

            return $inventory->refresh();
        });
    }

    /** INV-101 : figeage des quantités théoriques par lot. */
    public function start(string $id, ?string $actorId): Inventory
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            // Verrou du dépôt : deux inventaires ne démarrent pas simultanément sur les mêmes variantes (INV-105).
            $inventory = $this->lock($id, ['draft'], 'démarrer');
            Warehouse::query()->lockForUpdate()->findOrFail($inventory->warehouse_id);

            $balances = $this->db->table('stock_lot_balances')
                ->where(['company_id' => $inventory->company_id, 'warehouse_id' => $inventory->warehouse_id])
                ->where('qty_on_hand', '>', 0)
                ->when($inventory->scope === 'partial', fn ($q) => $q->whereIn('variant_id', $this->scopeVariants($inventory)))
                ->get(['variant_id', 'lot_id', 'qty_on_hand']);

            $variantIds = $inventory->scope === 'partial' ? $this->scopeVariants($inventory) : array_values(array_map('strval', $balances->pluck('variant_id')->unique()->all()));
            $this->assertNoOtherOpenInventory($inventory, $variantIds);

            foreach ($balances as $balance) {
                (new InventoryLine)->fill([
                    'inventory_id' => $inventory->id,
                    'variant_id' => $balance->variant_id,
                    'lot_id' => $balance->lot_id,
                    'qty_expected' => (int) $balance->qty_on_hand,
                ])->save();
            }

            // Variante du périmètre sans stock : ligne à zéro pour pouvoir saisir un comptage trouvé.
            foreach (array_diff($variantIds, $balances->pluck('variant_id')->all()) as $variantId) {
                (new InventoryLine)->fill(['inventory_id' => $inventory->id, 'variant_id' => $variantId, 'lot_id' => null, 'qty_expected' => 0])->save();
            }

            $inventory->forceFill(['status' => 'counting', 'snapshot_at' => CarbonImmutable::now(), 'version' => $inventory->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('inventory.started', 'inventory', $inventory->id, [], ['number' => $inventory->number]);

            return $inventory;
        });
    }

    public function count(string $id, string $lineId, int $quantity, ?string $note, string $actorId): InventoryLine
    {
        return $this->db->transaction(function () use ($id, $lineId, $quantity, $note, $actorId) {
            $inventory = $this->lock($id, ['counting'], 'saisir un comptage');
            $line = InventoryLine::query()->where('inventory_id', $inventory->id)->lockForUpdate()->findOrFail($lineId);

            if ($quantity < 0) {
                throw BusinessRuleViolation::invalidField('qty_counted', 'La quantité comptée ne peut pas être négative.');
            }

            $line->forceFill(['qty_counted' => $quantity, 'note' => $note, 'counted_by' => $actorId, 'counted_at' => CarbonImmutable::now()])->save();

            return $line;
        });
    }

    public function closeCounting(string $id, ?string $actorId): Inventory
    {
        return $this->transition($id, ['counting'], 'review', 'clôturer le comptage', $actorId);
    }

    public function reopen(string $id, ?string $actorId): Inventory
    {
        return $this->transition($id, ['review'], 'counting', 'rouvrir', $actorId);
    }

    public function cancel(string $id, ?string $actorId): Inventory
    {
        return $this->transition($id, ['draft', 'counting'], 'cancelled', 'annuler', $actorId);
    }

    /** INV-102, INV-103 : calcule les écarts réels et produit l'ajustement unique. */
    public function validate(string $id, ?string $reason, ?string $actorId): Inventory
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId) {
            $inventory = $this->lock($id, ['review'], 'valider');
            $lines = $inventory->lines()->get();

            if ($lines->contains(fn (InventoryLine $l) => $l->qty_counted === null)) {
                throw new BusinessRuleViolation('uncounted_lines', 'Comptage incomplet', 'Toutes les lignes doivent être comptées avant la validation.', 422);
            }

            $adjustmentLines = [];

            foreach ($this->differences($inventory, $lines) as $difference) {
                if ($difference['difference'] !== 0) {
                    $adjustmentLines[] = [
                        'variant_id' => $difference['variant_id'],
                        'lot_id' => $difference['difference'] < 0 ? $difference['lot_id'] : null,
                        'quantity' => $difference['difference'],
                        'kind' => 'correction',
                    ];
                }
            }

            $adjustmentId = null;

            if ($adjustmentLines !== []) {
                $adjustment = $this->adjustments->create($inventory->warehouse_id, $reason ?? "Inventaire {$inventory->number}", $adjustmentLines, $actorId, inventoryId: $inventory->id);
                $adjustmentId = $adjustment->id;
                $this->adjustments->submit($adjustment->id, $actorId);
            }

            $inventory->forceFill([
                'status' => 'validated',
                'validated_at' => CarbonImmutable::now(),
                'adjustment_id' => $adjustmentId,
                'version' => $inventory->version + 1,
                'updated_by' => $actorId,
            ])->save();
            $this->audit->record('inventory.validated', 'inventory', $inventory->id, [], ['number' => $inventory->number, 'adjustment_id' => $adjustmentId]);

            return $inventory;
        }, 3);
    }

    /**
     * Écart par ligne : compté − (théorique au snapshot + mouvements du lot depuis le snapshot).
     *
     * @param  Collection<int, InventoryLine>  $lines
     * @return list<array{line_id: string, variant_id: string, lot_id: string|null, expected: int, moved: int, counted: int|null, difference: int}>
     */
    public function differences(Inventory $inventory, Collection $lines): array
    {
        $since = $this->db->table('stock_movements')
            ->where(['company_id' => $inventory->company_id, 'warehouse_id' => $inventory->warehouse_id])
            ->where('occurred_at', '>=', $inventory->snapshot_at?->utc()->format('Y-m-d H:i:s.u'))
            ->selectRaw('lot_id, SUM(quantity) AS moved')->groupBy('lot_id')->pluck('moved', 'lot_id');

        $result = [];

        foreach ($lines as $line) {
            $moved = $line->lot_id !== null ? (int) ($since[$line->lot_id] ?? 0) : 0;
            $result[] = [
                'line_id' => $line->id,
                'variant_id' => $line->variant_id,
                'lot_id' => $line->lot_id,
                'expected' => $line->qty_expected,
                'moved' => $moved,
                'counted' => $line->qty_counted,
                'difference' => $line->qty_counted === null ? 0 : $line->qty_counted - ($line->qty_expected + $moved),
            ];
        }

        return $result;
    }

    /** @param list<string> $from */
    private function transition(string $id, array $from, string $to, string $action, ?string $actorId): Inventory
    {
        return $this->db->transaction(function () use ($id, $from, $to, $action, $actorId) {
            $inventory = $this->lock($id, $from, $action);
            $inventory->forceFill(['status' => $to, 'version' => $inventory->version + 1, 'updated_by' => $actorId])->save();

            return $inventory;
        });
    }

    /** @param list<string> $variantIds */
    private function assertNoOtherOpenInventory(Inventory $inventory, array $variantIds): void
    {
        $others = Inventory::query()->where('warehouse_id', $inventory->warehouse_id)->whereIn('status', ['counting', 'review'])
            ->whereKeyNot($inventory->id)->get();

        foreach ($others as $other) {
            $overlap = $other->scope === 'full' || $inventory->scope === 'full'
                || array_intersect($variantIds, $this->scopeVariants($other)) !== [];

            if ($overlap) {
                throw new BusinessRuleViolation('inventory_open', 'Inventaire en cours', "L'inventaire {$other->number} est déjà ouvert sur ces articles dans ce dépôt.", 409);
            }
        }
    }

    /** @return list<string> */
    private function scopeVariants(Inventory $inventory): array
    {
        /** @var list<string> */
        return $inventory->scope_filter['variant_ids'] ?? [];
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): Inventory
    {
        $inventory = Inventory::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($inventory->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('l\'inventaire', $inventory->status, $action);
        }

        return $inventory;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
