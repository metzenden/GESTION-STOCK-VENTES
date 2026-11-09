<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Infrastructure\Models\StockAdjustment;
use App\Modules\Inventory\Infrastructure\Models\StockAdjustmentLine;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Ajustements de stock (L2-11) : brouillon → comptabilisé, ou → en attente d'approbation → comptabilisé / rejeté.
 *
 * - INV-005 : en attente d'approbation, les sorties sont bloquées (pending_adjustment) sans mouvement.
 * - INV-006 : une entrée crée un lot « adjustment » au coût saisi, à défaut au coût du dernier lot définitif (D-048).
 * - INV-007 : une sortie ne porte que sur le disponible du lot.
 */
final class AdjustmentService
{
    public const SOURCE = 'stock_adjustment';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly StockLedger $ledger,
        private readonly VariantCatalog $catalog,
        private readonly ApprovalGate $approvals,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<array{variant_id: string, lot_id?: string|null, quantity: int, kind?: string, unit_cost?: string|null}>  $lines
     */
    public function create(string $warehouseId, ?string $reason, array $lines, ?string $actorId, ?string $inventoryId = null, ?string $transferId = null): StockAdjustment
    {
        return $this->db->transaction(function () use ($warehouseId, $reason, $lines, $actorId, $inventoryId, $transferId) {
            $this->assertWarehouse($warehouseId);
            $companyId = $this->tenant->requireCompanyId();

            $adjustment = new StockAdjustment;
            $adjustment->fill([
                'number' => $this->numbers->next($companyId, DocumentType::StockAdjustment, $this->today()),
                'warehouse_id' => $warehouseId,
                'inventory_id' => $inventoryId,
                'transfer_id' => $transferId,
                'status' => 'draft',
                'reason' => $reason,
            ])->forceFill(['created_by' => $actorId])->save();

            $this->replaceLines($adjustment, $lines);

            // Valeurs posées par la base (version).
            return $adjustment->refresh();
        });
    }

    /** @param list<array{variant_id: string, lot_id?: string|null, quantity: int, kind?: string, unit_cost?: string|null}>|null $lines */
    public function update(string $id, ?string $reason, ?array $lines, ?int $expectedVersion, ?string $actorId): StockAdjustment
    {
        return $this->db->transaction(function () use ($id, $reason, $lines, $expectedVersion, $actorId) {
            $adjustment = $this->lock($id, 'draft', 'modifier');

            if ($expectedVersion !== null && $expectedVersion !== $adjustment->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            if ($lines !== null) {
                $this->replaceLines($adjustment, $lines);
            }

            $adjustment->forceFill(['reason' => $reason ?? $adjustment->reason, 'version' => $adjustment->version + 1, 'updated_by' => $actorId])->save();

            return $adjustment;
        });
    }

    /** Comptabilise, ou met en attente d'approbation avec blocage provisoire des sorties. */
    public function submit(string $id, ?string $actorId): StockAdjustment
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $adjustment = $this->lock($id, 'draft', 'soumettre');
            $lines = $adjustment->lines()->get();

            if ($lines->isEmpty()) {
                throw BusinessRuleViolation::invalidField('lines', 'L\'ajustement ne contient aucune ligne.');
            }

            $context = [
                'warehouse_id' => $adjustment->warehouse_id,
                'total_out' => -$lines->where('quantity', '<', 0)->sum('quantity'),
                'total_in' => $lines->where('quantity', '>', 0)->sum('quantity'),
                'reason' => $adjustment->reason,
            ];

            if ($this->approvals->requiresApproval('stock_adjustment', $context)) {
                foreach ($lines->where('quantity', '<', 0) as $line) {
                    $holdId = $this->ledger->hold($adjustment->warehouse_id, (string) $line->lot_id, -$line->quantity, 'pending_adjustment', self::SOURCE, $adjustment->id);
                    $line->forceFill(['hold_id' => $holdId])->save();
                }

                $adjustment->forceFill(['status' => 'pending_approval', 'version' => $adjustment->version + 1, 'updated_by' => $actorId])->save();
                $this->audit->record('stock_adjustment.submitted', 'stock_adjustment', $adjustment->id, [], ['number' => $adjustment->number, 'approval' => true]);
                $this->approvals->submit('stock_adjustment', 'stock_adjustment', $adjustment->id, [], "Ajustement de stock {$adjustment->number} : {$adjustment->reason}");

                return $adjustment;
            }

            return $this->post($adjustment, $actorId);
        }, 3);
    }

    /** Effet de l'approbation (appelé par le moteur d'approbation, L6). */
    public function approve(string $id, ?string $actorId): StockAdjustment
    {
        return $this->db->transaction(fn () => $this->post($this->lock($id, 'pending_approval', 'approuver'), $actorId), 3);
    }

    public function reject(string $id, ?string $actorId): StockAdjustment
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $adjustment = $this->lock($id, 'pending_approval', 'rejeter');

            foreach ($adjustment->lines()->whereNotNull('hold_id')->get() as $line) {
                $this->ledger->releaseHold((string) $line->hold_id);
            }

            $adjustment->forceFill(['status' => 'rejected', 'version' => $adjustment->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('stock_adjustment.rejected', 'stock_adjustment', $adjustment->id);

            return $adjustment;
        });
    }

    public function cancel(string $id, ?string $actorId): StockAdjustment
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $adjustment = $this->lock($id, 'draft', 'annuler');
            $adjustment->forceFill(['status' => 'cancelled', 'version' => $adjustment->version + 1, 'updated_by' => $actorId])->save();

            return $adjustment;
        });
    }

    private function post(StockAdjustment $adjustment, ?string $actorId): StockAdjustment
    {
        $now = CarbonImmutable::now();
        $currency = $this->companies->find($adjustment->company_id)->referenceCurrency ?? 'XOF';

        foreach ($adjustment->lines()->get() as $line) {
            $source = new StockSource(self::SOURCE, $adjustment->id, $line->id, $adjustment->reason);

            if ($line->quantity > 0) {
                $cost = $line->unit_cost ?? $this->lastFinalCost($line->variant_id);

                if ($cost === null) {
                    throw BusinessRuleViolation::invalidField("lines/{$line->id}/unit_cost", 'Aucun lot antérieur : indiquez le coût unitaire de cette entrée.');
                }

                $lotId = $this->ledger->createLot($line->variant_id, 'adjustment', $line->id, $now, $line->quantity, $cost, $currency);
                $this->ledger->receive($adjustment->warehouse_id, $lotId, $line->quantity, MovementType::AdjustmentIn->value, $source, $now);
                $line->forceFill(['created_lot_id' => $lotId, 'unit_cost' => $cost])->save();

                continue;
            }

            $type = in_array($line->kind, ['loss', 'damage'], true) ? MovementType::Loss : MovementType::AdjustmentOut;

            if ($line->hold_id !== null) {
                $this->ledger->consumeHold($line->hold_id, $type->value, $source, $now);
            } else {
                $this->ledger->issue($adjustment->warehouse_id, (string) $line->lot_id, -$line->quantity, $type->value, $source, $now);
            }
        }

        $adjustment->forceFill(['status' => 'posted', 'posted_at' => $now, 'version' => $adjustment->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record('stock_adjustment.posted', 'stock_adjustment', $adjustment->id, [], ['number' => $adjustment->number]);

        return $adjustment;
    }

    /** @param list<array{variant_id: string, lot_id?: string|null, quantity: int, kind?: string, unit_cost?: string|null}> $lines */
    private function replaceLines(StockAdjustment $adjustment, array $lines): void
    {
        $variants = $this->catalog->describe(array_values(array_unique(array_column($lines, 'variant_id'))));
        StockAdjustmentLine::query()->where('adjustment_id', $adjustment->id)->delete();

        foreach ($lines as $index => $line) {
            $variant = $variants[$line['variant_id']] ?? throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Cette variante n\'existe pas.');

            if (! $variant->isStocked()) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Une prestation n\'a pas de stock.');
            }

            if ($line['quantity'] === 0) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/quantity", 'La quantité ne peut pas être nulle.');
            }

            $lotId = $line['lot_id'] ?? null;

            if ($line['quantity'] < 0) {
                if ($lotId === null) {
                    throw BusinessRuleViolation::invalidField("lines/{$index}/lot_id", 'Une sortie doit indiquer le lot concerné.');
                }

                if ($this->db->table('stock_lots')->where(['company_id' => $adjustment->company_id, 'id' => $lotId, 'variant_id' => $variant->id])->doesntExist()) {
                    throw BusinessRuleViolation::invalidField("lines/{$index}/lot_id", 'Ce lot ne correspond pas à la variante.');
                }
            } elseif ($lotId !== null) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/lot_id", 'Une entrée crée un nouveau lot : n\'indiquez pas de lot.');
            }

            (new StockAdjustmentLine)->fill([
                'adjustment_id' => $adjustment->id,
                'variant_id' => $variant->id,
                'lot_id' => $lotId,
                'quantity' => $line['quantity'],
                'kind' => $line['kind'] ?? ($line['quantity'] > 0 ? 'gain' : 'loss'),
                'unit_cost' => $line['quantity'] > 0 ? ($line['unit_cost'] ?? null) : null,
            ])->save();
        }
    }

    private function lastFinalCost(string $variantId): ?string
    {
        $cost = $this->db->table('stock_lots')
            ->where(['company_id' => $this->tenant->requireCompanyId(), 'variant_id' => $variantId, 'cost_status' => 'final'])
            ->orderByDesc('received_at')->orderByDesc('lot_number')->value('unit_cost');

        return $cost === null ? null : (string) $cost;
    }

    private function lock(string $id, string $expected, string $action): StockAdjustment
    {
        $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($id);

        if ($adjustment->status !== $expected) {
            throw BusinessRuleViolation::invalidTransition('l\'ajustement', $adjustment->status, $action);
        }

        return $adjustment;
    }

    private function assertWarehouse(string $warehouseId): void
    {
        $warehouse = Warehouse::query()->find($warehouseId);

        if ($warehouse === null || $warehouse->status !== 'active') {
            throw BusinessRuleViolation::invalidField('warehouse_id', 'Ce dépôt n\'existe pas ou est inactif.');
        }
    }

    private function today(): CarbonImmutable
    {
        $timezone = $this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar';

        return CarbonImmutable::now($timezone);
    }
}
