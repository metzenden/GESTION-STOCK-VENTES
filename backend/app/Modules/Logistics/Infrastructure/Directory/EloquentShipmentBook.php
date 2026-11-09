<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Directory;

use App\Modules\Logistics\Contracts\CostingLine;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Logistics\Contracts\ShipmentSummary;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceipt;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceiptLine;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Procurement\Contracts\PurchaseOrderBook;

final class EloquentShipmentBook implements ShipmentBook
{
    public function __construct(private readonly PurchaseOrderBook $orders) {}

    public function find(string $shipmentId): ?ShipmentSummary
    {
        $shipment = Shipment::query()->find($shipmentId);

        return $shipment === null ? null : new ShipmentSummary($shipment->id, $shipment->number, $shipment->status);
    }

    public function lock(string $shipmentId): ShipmentSummary
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

        return new ShipmentSummary($shipment->id, $shipment->number, $shipment->status);
    }

    public function costingLines(string $shipmentId): array
    {
        $lines = ShipmentLine::query()->where('shipment_id', $shipmentId)->orderBy('position')->get();
        $received = GoodsReceiptLine::query()
            ->whereIn('goods_receipt_id', GoodsReceipt::query()->where('shipment_id', $shipmentId)->where('status', 'validated')->select('id'))
            ->get()->groupBy('shipment_line_id');
        $poLines = $this->orders->lines(array_values(array_map('strval', $lines->pluck('purchase_order_line_id')->filter()->all())));
        $rates = [];

        foreach ($poLines as $poLine) {
            $rates[$poLine->id] ??= $this->orders->summary($poLine->purchaseOrderId)['estimated_rate'] ?? null;
        }

        return array_values($lines->map(function (ShipmentLine $line) use ($received, $rates) {
            $receiptLines = $received->get($line->id, collect());

            return new CostingLine(
                $line->id,
                $line->position,
                $line->variant_id,
                $line->supplier_id,
                $line->qty_expected,
                (int) $receiptLines->sum(fn (GoodsReceiptLine $l) => $l->stockQuantity()),
                $line->pieces_per_carton,
                $line->cbm_total,
                $line->purchase_currency,
                $line->purchase_unit_price,
                $line->purchase_order_line_id !== null ? ($rates[$line->purchase_order_line_id] ?? null) : null,
                array_values(array_map('strval', $receiptLines->pluck('lot_id')->filter()->all())),
            );
        })->all());
    }

    public function hasLine(string $shipmentId, string $lineId): bool
    {
        return ShipmentLine::query()->whereKey($lineId)->where('shipment_id', $shipmentId)->exists();
    }
}
