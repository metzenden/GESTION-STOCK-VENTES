<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Logistics\Contracts\ProvisionalCostEstimator;
use App\Modules\Logistics\Contracts\ReceivedLine;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceipt;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceiptLine;
use App\Modules\Logistics\Infrastructure\Models\ReceiptScanEvent;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Procurement\Contracts\PurchaseOrderBook;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Réceptions (L3-08, L3-09) : brouillon → comptage → validée (une seule fois, IMP-004).
 * Un comptage ne touche jamais le stock (IMP-002) ; la validation crée un lot par ligne,
 * le mouvement `receipt` et bloque l'endommagé conservé (IMP-003, D-046).
 */
final class GoodsReceiptService
{
    public const SOURCE = 'goods_receipt';

    public const HOLD_SOURCE = 'goods_receipt_line';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly StockLedger $ledger,
        private readonly VariantCatalog $catalog,
        private readonly WarehouseDirectory $warehouses,
        private readonly PurchaseOrderBook $orders,
        private readonly ProvisionalCostEstimator $costs,
        private readonly ShipmentService $shipments,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /** Réception d'arrivage : reprend les reliquats des articles attendus dans ce dépôt (IMP-009). */
    public function createForShipment(string $shipmentId, string $warehouseId, string $mode, ?string $note, ?string $actorId): GoodsReceipt
    {
        return $this->db->transaction(function () use ($shipmentId, $warehouseId, $mode, $note, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

            if (! in_array($shipment->status, ['arrived', 'unloading', 'partially_received'], true)) {
                throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, 'ouvrir une réception');
            }

            $this->assertWarehouse($warehouseId);
            $busy = GoodsReceiptLine::query()->whereNotNull('shipment_line_id')
                ->whereIn('goods_receipt_id', GoodsReceipt::query()->where('shipment_id', $shipment->id)->whereIn('status', ['draft', 'counting'])->select('id'))
                ->pluck('shipment_line_id')->all();
            $lines = $shipment->lines()->get()->filter(fn (ShipmentLine $l) => $l->destination_warehouse_id === $warehouseId
                && $l->remaining() > 0 && ! in_array($l->id, $busy, true));

            if ($lines->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_receive', 'Rien à réceptionner', 'Aucun article attendu ne reste à recevoir dans ce dépôt.');
            }

            $receipt = $this->newReceipt(['shipment_id' => $shipment->id], $warehouseId, $mode, $note, $actorId);

            foreach ($lines as $line) {
                (new GoodsReceiptLine)->fill([
                    'goods_receipt_id' => $receipt->id,
                    'shipment_line_id' => $line->id,
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'variant_id' => $line->variant_id,
                    'qty_expected' => $line->remaining(),
                ])->save();
            }

            $this->shipments->refreshReceptionStatus($shipment->id, $actorId);

            return $receipt->refresh();
        });
    }

    /** Réception directe d'une commande (sans arrivage) : lignes non prises en charge par un arrivage. */
    public function createForPurchaseOrder(string $purchaseOrderId, string $warehouseId, string $mode, ?string $note, ?string $actorId): GoodsReceipt
    {
        return $this->db->transaction(function () use ($purchaseOrderId, $warehouseId, $mode, $note, $actorId) {
            $this->assertWarehouse($warehouseId);
            $lines = $this->orders->receivableLines($purchaseOrderId);
            $ids = array_map(fn ($l) => $l->id, $lines);
            $shipped = ShipmentLine::query()->whereIn('purchase_order_line_id', $ids)
                ->whereIn('shipment_id', Shipment::query()->where('status', '<>', 'cancelled')->select('id'))->pluck('purchase_order_line_id')->all();
            $busy = GoodsReceiptLine::query()->whereIn('purchase_order_line_id', $ids)
                ->whereIn('goods_receipt_id', GoodsReceipt::query()->whereIn('status', ['draft', 'counting'])->select('id'))->pluck('purchase_order_line_id')->all();
            $open = array_filter($lines, fn ($l) => $l->remaining() > 0 && ! in_array($l->id, $shipped, true) && ! in_array($l->id, $busy, true));

            if ($open === []) {
                throw new BusinessRuleViolation('nothing_to_receive', 'Rien à réceptionner', 'Aucune ligne de cette commande ne reste à recevoir directement.');
            }

            $receipt = $this->newReceipt(['purchase_order_id' => $purchaseOrderId], $warehouseId, $mode, $note, $actorId);

            foreach ($open as $line) {
                (new GoodsReceiptLine)->fill([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $line->id,
                    'variant_id' => $line->variantId,
                    'qty_expected' => $line->remaining(),
                ])->save();
            }

            return $receipt->refresh();
        });
    }

    public function startCounting(string $id, ?string $actorId): GoodsReceipt
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $receipt = $this->lock($id, ['draft'], 'commencer le comptage');

            return $this->mark($receipt, 'counting', $actorId);
        });
    }

    /** Saisie manuelle d'une ligne (IMP-002 : sans effet sur le stock). */
    public function count(string $id, string $lineId, int $accepted, int $damaged, string $disposition, ?string $note, ?string $actorId): GoodsReceiptLine
    {
        return $this->db->transaction(function () use ($id, $lineId, $accepted, $damaged, $disposition, $note, $actorId) {
            $receipt = $this->lock($id, ['draft', 'counting'], 'saisir un comptage');
            $line = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->lockForUpdate()->findOrFail($lineId);

            if ($accepted < 0 || $damaged < 0) {
                throw BusinessRuleViolation::invalidField('qty_accepted', 'Les quantités ne peuvent pas être négatives.');
            }

            $line->forceFill([
                'qty_accepted' => $accepted,
                'qty_damaged' => $damaged,
                'damaged_disposition' => $disposition,
                'note' => $note,
                'is_counted' => true,
                'updated_by' => $actorId,
            ])->save();

            if ($receipt->status === 'draft') {
                $this->mark($receipt, 'counting', $actorId);
            }

            return $line->refresh();
        });
    }

    /**
     * Scan d'un code-barres : un conditionnement compte son facteur en pièces.
     * Un même client_event_id n'est compté qu'une fois (IMP-010) ; le rejeu renvoie l'événement d'origine.
     *
     * @return array{event: ReceiptScanEvent, duplicate: bool}
     */
    public function scan(string $id, string $clientEventId, string $code, int $units, bool $damaged, ?CarbonImmutable $scannedAt, string $actorId): array
    {
        $existing = ReceiptScanEvent::query()->where('goods_receipt_id', $id)->where('client_event_id', $clientEventId)->first();

        if ($existing !== null) {
            return ['event' => $existing, 'duplicate' => true];
        }

        try {
            return $this->db->transaction(function () use ($id, $clientEventId, $code, $units, $damaged, $scannedAt, $actorId) {
                $receipt = $this->lock($id, ['draft', 'counting'], 'scanner');
                $unit = $this->catalog->unitByBarcode($code);
                $line = null;
                $outcome = 'unknown';

                if ($unit !== null) {
                    $candidates = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->where('variant_id', $unit->variantId)->lockForUpdate()->get();
                    $line = $candidates->first(fn (GoodsReceiptLine $l) => $l->qty_counted < $l->qty_expected) ?? $candidates->first();
                    $outcome = $line === null ? 'not_expected' : 'counted';
                }

                $qty = $units * ($unit->factor ?? 1);
                $event = new ReceiptScanEvent;
                $event->fill([
                    'goods_receipt_id' => $receipt->id,
                    'goods_receipt_line_id' => $line?->id,
                    'scanned_code' => $code,
                    'resolved_unit_id' => $unit?->id,
                    'qty_base' => $qty,
                    'damaged' => $damaged,
                    'outcome' => $outcome,
                    'scanned_by' => $actorId,
                    'scanned_at' => ($scannedAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s.u'),
                    'client_event_id' => $clientEventId,
                ])->save();

                if ($line !== null) {
                    $line->forceFill([
                        $damaged ? 'qty_damaged' : 'qty_accepted' => ($damaged ? $line->qty_damaged : $line->qty_accepted) + $qty,
                        'is_counted' => true,
                    ])->save();
                }

                if ($receipt->status === 'draft') {
                    $this->mark($receipt, 'counting', $actorId);
                }

                return ['event' => $event->refresh(), 'duplicate' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Deux envois simultanés du même événement : le second relit le premier.
            return ['event' => ReceiptScanEvent::query()->where('goods_receipt_id', $id)->where('client_event_id', $clientEventId)->firstOrFail(), 'duplicate' => true];
        }
    }

    /** IMP-003, IMP-004, IMP-008 : lots, mouvements et blocages, une seule fois. */
    public function validate(string $id, bool $canAcceptSurplus, ?string $actorId): GoodsReceipt
    {
        return $this->db->transaction(function () use ($id, $canAcceptSurplus, $actorId) {
            $receipt = $this->lock($id, ['counting'], 'valider');
            $lines = $receipt->lines()->lockForUpdate()->get();
            $shipmentLines = ShipmentLine::query()->whereKey($lines->pluck('shipment_line_id')->filter()->values())->lockForUpdate()->get()->keyBy('id');
            $poLines = $this->orders->lines(array_values(array_map('strval', $lines->pluck('purchase_order_line_id')->filter()->all())));
            $estimatedRate = null;

            if ($receipt->purchase_order_id !== null) {
                $estimatedRate = $this->orders->summary($receipt->purchase_order_id)['estimated_rate'] ?? null;
            }

            $toValue = [];

            foreach ($lines as $index => $line) {
                if ($line->qty_surplus > 0 && ! $canAcceptSurplus) {
                    throw new BusinessRuleViolation('surplus_not_allowed', 'Surplus non autorisé',
                        'Une quantité comptée dépasse l\'attendu : la permission d\'accepter un surplus est nécessaire.', 403, [],
                        [['pointer' => "/lines/{$index}", 'code' => 'surplus', 'message' => "Surplus de {$line->qty_surplus}."]]);
                }

                if ($line->stockQuantity() === 0) {
                    continue;
                }

                $shipmentLine = $line->shipment_line_id !== null ? $shipmentLines[$line->shipment_line_id] : null;
                $poLine = $line->purchase_order_line_id !== null ? ($poLines[$line->purchase_order_line_id] ?? null) : null;
                $rate = $estimatedRate;

                if ($shipmentLine?->purchase_order_line_id !== null && $poLine !== null) {
                    $rate = $this->orders->summary($poLine->purchaseOrderId)['estimated_rate'] ?? null;
                }

                $toValue[] = new ReceivedLine(
                    $line->id,
                    $receipt->shipment_id,
                    $shipmentLine?->id,
                    $line->variant_id,
                    $line->stockQuantity(),
                    $shipmentLine->purchase_currency ?? $poLine->currency ?? throw new \LogicException('Ligne sans origine.'),
                    $shipmentLine->purchase_unit_price ?? $poLine->unitPricePerPiece ?? '0',
                    $rate,
                );
            }

            $costs = $this->costs->estimate($toValue);
            $now = CarbonImmutable::now();
            $poReceived = [];

            foreach ($lines as $line) {
                $stock = $line->stockQuantity();

                if ($stock > 0) {
                    $cost = $costs[$line->id];
                    $lotId = $this->ledger->createLot($line->variant_id, 'receipt', $receipt->id, $now, $stock, $cost['unit_cost'], $cost['currency'], $cost['cost_status'], $receipt->shipment_id);
                    $this->ledger->receive($receipt->warehouse_id, $lotId, $stock, 'receipt', new StockSource(self::SOURCE, $receipt->id, $line->id), $now);

                    if ($line->damaged_disposition === 'hold' && $line->qty_damaged > 0) {
                        $this->ledger->hold($receipt->warehouse_id, $lotId, $line->qty_damaged, 'damaged', self::HOLD_SOURCE, $line->id, "Réception {$receipt->number}");
                    }

                    $line->forceFill(['lot_id' => $lotId])->save();
                }

                if ($line->shipment_line_id !== null) {
                    $shipmentLine = $shipmentLines[$line->shipment_line_id] ?? throw new \LogicException('Article attendu absent.');
                    $shipmentLine->forceFill(['qty_received' => $shipmentLine->qty_received + $line->qty_counted])->save();
                }

                if ($line->purchase_order_line_id !== null && $line->qty_counted > 0) {
                    $poReceived[$line->purchase_order_line_id] = $line->qty_counted;
                }
            }

            $this->orders->recordReceipt($poReceived);
            $this->catalog->markHistory(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $receipt->forceFill(['validated_at' => $now, 'validated_by' => $actorId]);
            $this->mark($receipt, 'validated', $actorId);

            if ($receipt->shipment_id !== null) {
                $this->shipments->refreshReceptionStatus($receipt->shipment_id, $actorId);
            }

            return $receipt->refresh();
        }, 3);
    }

    public function cancel(string $id, ?string $actorId): GoodsReceipt
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $receipt = $this->lock($id, ['draft', 'counting'], 'annuler');
            $this->mark($receipt, 'cancelled', $actorId);

            if ($receipt->shipment_id !== null) {
                $this->shipments->refreshReceptionStatus($receipt->shipment_id, $actorId);
            }

            return $receipt->refresh();
        });
    }

    /** @param array<string, string> $origin */
    private function newReceipt(array $origin, string $warehouseId, string $mode, ?string $note, ?string $actorId): GoodsReceipt
    {
        $receipt = new GoodsReceipt;
        $receipt->fill($origin + [
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::GoodsReceipt, $this->today()),
            'warehouse_id' => $warehouseId,
            'counting_mode' => $mode,
            'note' => $note,
            'status' => 'draft',
        ])->forceFill(['created_by' => $actorId])->save();
        $this->audit->record('goods_receipt.created', 'goods_receipt', $receipt->id, [], ['number' => $receipt->number] + $origin);

        return $receipt;
    }

    private function assertWarehouse(string $warehouseId): void
    {
        if (! $this->warehouses->isActive($warehouseId)) {
            throw BusinessRuleViolation::invalidField('warehouse_id', 'Ce dépôt n\'existe pas ou est inactif.');
        }
    }

    private function mark(GoodsReceipt $receipt, string $status, ?string $actorId): GoodsReceipt
    {
        $previous = $receipt->status;
        $receipt->forceFill(['status' => $status, 'version' => $receipt->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("goods_receipt.{$status}", 'goods_receipt', $receipt->id, ['status' => $previous], ['status' => $status, 'number' => $receipt->number]);

        return $receipt;
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($receipt->status, $expected, true)) {
            if ($receipt->status === 'validated' && $action === 'valider') {
                throw BusinessRuleViolation::alreadyProcessed('Cette réception est déjà validée.');
            }

            throw BusinessRuleViolation::invalidTransition('la réception', $receipt->status, $action);
        }

        return $receipt;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
