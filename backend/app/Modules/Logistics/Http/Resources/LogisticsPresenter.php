<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Http\Resources;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Logistics\Infrastructure\Models\Container;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceipt;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceiptLine;
use App\Modules\Logistics\Infrastructure\Models\ReceiptScanEvent;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Représentations des arrivages et réceptions. Les prix d'achat ne sont visibles qu'avec
 * la permission de consulter les commandes d'achat (GEN-005) ; les champs masqués sont listés dans meta.redacted.
 */
final class LogisticsPresenter
{
    public const PRICE_FIELDS = ['purchase_unit_price', 'purchase_amount'];

    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly SupplierDirectory $suppliers,
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    public static function canSeePrices(): bool
    {
        return Gate::allows('purchase_orders.view');
    }

    /** @return array<string, mixed> */
    public function shipment(Shipment $shipment, bool $detailed = true): array
    {
        $data = [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'reference' => $shipment->reference,
            'carrier' => $shipment->carrier,
            'origin_country' => $shipment->origin_country,
            'shipped_at' => $shipment->shipped_at?->toDateString(),
            'eta' => $shipment->eta?->toDateString(),
            'arrived_at' => $shipment->arrived_at?->toDateString(),
            'status' => $shipment->status,
            'default_warehouse_id' => $shipment->default_warehouse_id,
            'notes' => $shipment->notes,
            'closed_at' => $shipment->closed_at?->toIso8601ZuluString(),
            'version' => $shipment->version,
            'created_at' => $shipment->created_at->toIso8601ZuluString(),
        ];

        if ($detailed) {
            $data['containers'] = $shipment->containers()->get()->map(fn (Container $c) => $this->container($c))->values()->all();
            $data['purchase_orders'] = $this->db->table('shipment_purchase_orders as l')
                ->join('purchase_orders as o', fn ($j) => $j->on('o.id', '=', 'l.purchase_order_id')->on('o.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $this->tenant->requireCompanyId())->where('l.shipment_id', $shipment->id)
                ->orderBy('o.number')->get(['o.id', 'o.number', 'o.status'])
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status])->values()->all();
            $data['lines'] = $this->lines($shipment->lines()->get());
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function container(Container $c): array
    {
        return ['id' => $c->id, 'container_number' => $c->container_number, 'container_type' => $c->container_type, 'seal_number' => $c->seal_number];
    }

    /**
     * @param  Collection<int, ShipmentLine>  $lines
     * @return list<array<string, mixed>>
     */
    public function lines(Collection $lines): array
    {
        $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
        $suppliers = $this->suppliers->describe(array_values(array_map('strval', $lines->pluck('supplier_id')->filter()->unique()->all())));
        $prices = self::canSeePrices();

        return array_values($lines->map(fn (ShipmentLine $l) => array_filter([
            'id' => $l->id,
            'position' => $l->position,
            'container_id' => $l->container_id,
            'purchase_order_line_id' => $l->purchase_order_line_id,
            'supplier' => $l->supplier_id !== null && isset($suppliers[$l->supplier_id]) ? $suppliers[$l->supplier_id]->toArray() : null,
            'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
            'destination_warehouse_id' => $l->destination_warehouse_id,
            'cartons' => $l->cartons,
            'pieces_per_carton' => $l->pieces_per_carton,
            'qty_expected' => $l->qty_expected,
            'qty_received' => $l->qty_received,
            'qty_remaining' => $l->remaining(),
            'is_closed' => $l->is_closed,
            'cbm_total' => $l->cbm_total,
            'purchase_currency' => $l->purchase_currency,
            'purchase_unit_price' => $prices ? $l->purchase_unit_price : '__redacted__',
            'purchase_amount' => $prices ? $l->purchase_amount : '__redacted__',
            'packing_list_line_id' => $l->packing_list_line_id,
        ], fn ($v) => $v !== '__redacted__'))->all());
    }

    /** @return array<string, mixed> */
    public function receipt(GoodsReceipt $receipt, bool $withLines = true): array
    {
        $data = [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'shipment_id' => $receipt->shipment_id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'warehouse_id' => $receipt->warehouse_id,
            'status' => $receipt->status,
            'counting_mode' => $receipt->counting_mode,
            'note' => $receipt->note,
            'validated_at' => $receipt->validated_at?->toIso8601ZuluString(),
            'version' => $receipt->version,
            'created_at' => $receipt->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $receipt->lines()->get();
            $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $lots = $this->db->table('stock_lots')->where('company_id', $this->tenant->requireCompanyId())
                ->whereIn('id', $lines->pluck('lot_id')->filter()->values())->pluck('lot_number', 'id')->all();
            $data['lines'] = $lines->map(fn (GoodsReceiptLine $l) => [
                'id' => $l->id,
                'shipment_line_id' => $l->shipment_line_id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
                'qty_expected' => $l->qty_expected,
                'qty_counted' => $l->qty_counted,
                'qty_accepted' => $l->qty_accepted,
                'qty_damaged' => $l->qty_damaged,
                'qty_missing' => $l->qty_missing,
                'qty_surplus' => $l->qty_surplus,
                'damaged_disposition' => $l->damaged_disposition,
                'is_counted' => $l->is_counted,
                'lot_id' => $l->lot_id,
                'lot_number' => $l->lot_id !== null ? ($lots[$l->lot_id] ?? null) : null,
                'note' => $l->note,
            ])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function scan(ReceiptScanEvent $event): array
    {
        return [
            'id' => $event->id,
            'client_event_id' => $event->client_event_id,
            'scanned_code' => $event->scanned_code,
            'goods_receipt_line_id' => $event->goods_receipt_line_id,
            'qty_base' => $event->qty_base,
            'damaged' => $event->damaged,
            'outcome' => $event->outcome,
            'scanned_at' => $event->scanned_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
