<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Infrastructure\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Models\InventoryLine;
use App\Modules\Inventory\Infrastructure\Models\StockAdjustment;
use App\Modules\Inventory\Infrastructure\Models\StockAdjustmentLine;
use App\Modules\Inventory\Infrastructure\Models\StockTransfer;
use App\Modules\Inventory\Infrastructure\Models\StockTransferLine;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Gate;

/** Représentations des documents de stock, enrichies des libellés de variantes et des numéros de lots. */
final class StockDocumentPresenter
{
    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly ConnectionInterface $db,
    ) {}

    /** @return array<string, mixed> */
    public function adjustment(StockAdjustment $adjustment, bool $withLines = true): array
    {
        $data = [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'warehouse_id' => $adjustment->warehouse_id,
            'inventory_id' => $adjustment->inventory_id,
            'transfer_id' => $adjustment->transfer_id,
            'status' => $adjustment->status,
            'reason' => $adjustment->reason,
            'posted_at' => $adjustment->posted_at?->toIso8601ZuluString(),
            'version' => $adjustment->version,
            'created_at' => $adjustment->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $adjustment->lines()->get();
            [$variants, $lots] = $this->lookups($lines->pluck('variant_id')->all(), $lines->pluck('lot_id')->merge($lines->pluck('created_lot_id'))->filter()->all());
            $sensitive = Gate::allows('products.view_sensitive');
            $data['lines'] = $lines->map(fn (StockAdjustmentLine $l) => array_filter([
                'id' => $l->id,
                'variant' => $variants[$l->variant_id] ?? null,
                'lot_id' => $l->lot_id,
                'lot_number' => $l->lot_id !== null ? ($lots[$l->lot_id] ?? null) : ($l->created_lot_id !== null ? ($lots[$l->created_lot_id] ?? null) : null),
                'quantity' => $l->quantity,
                'kind' => $l->kind,
                'unit_cost' => $sensitive ? $l->unit_cost : '__redacted__',
            ], fn ($v) => $v !== '__redacted__'))->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function inventory(Inventory $inventory, bool $withLines = true): array
    {
        $data = [
            'id' => $inventory->id,
            'number' => $inventory->number,
            'warehouse_id' => $inventory->warehouse_id,
            'scope' => $inventory->scope,
            'variant_ids' => $inventory->scope_filter['variant_ids'] ?? [],
            'status' => $inventory->status,
            'snapshot_at' => $inventory->snapshot_at?->toIso8601ZuluString(),
            'validated_at' => $inventory->validated_at?->toIso8601ZuluString(),
            'adjustment_id' => $inventory->adjustment_id,
            'note' => $inventory->note,
            'version' => $inventory->version,
            'created_at' => $inventory->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $inventory->lines()->get();
            [$variants, $lots] = $this->lookups($lines->pluck('variant_id')->all(), $lines->pluck('lot_id')->filter()->all());
            $differences = $inventory->snapshot_at !== null ? collect(app(InventoryService::class)->differences($inventory, $lines))->keyBy('line_id') : collect();

            $data['lines'] = $lines->map(fn (InventoryLine $l) => [
                'id' => $l->id,
                'variant' => $variants[$l->variant_id] ?? null,
                'lot_id' => $l->lot_id,
                'lot_number' => $l->lot_id !== null ? ($lots[$l->lot_id] ?? null) : null,
                'qty_expected' => $l->qty_expected,
                'qty_moved_since_snapshot' => $differences[$l->id]['moved'] ?? 0,
                'qty_counted' => $l->qty_counted,
                'difference' => $l->qty_counted !== null ? ($differences[$l->id]['difference'] ?? null) : null,
                'counted_at' => $l->counted_at?->toIso8601ZuluString(),
                'note' => $l->note,
            ])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function transfer(StockTransfer $transfer, bool $withLines = true): array
    {
        $data = [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'from_warehouse_id' => $transfer->from_warehouse_id,
            'to_warehouse_id' => $transfer->to_warehouse_id,
            'status' => $transfer->status,
            'shipped_at' => $transfer->shipped_at?->toIso8601ZuluString(),
            'received_at' => $transfer->received_at?->toIso8601ZuluString(),
            'note' => $transfer->note,
            'version' => $transfer->version,
            'created_at' => $transfer->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $transfer->lines()->get();
            [$variants, $lots] = $this->lookups($lines->pluck('variant_id')->all(), $lines->pluck('lot_id')->filter()->all());
            $data['lines'] = $lines->map(fn (StockTransferLine $l) => [
                'id' => $l->id,
                'variant' => $variants[$l->variant_id] ?? null,
                'lot_id' => $l->lot_id,
                'lot_number' => $l->lot_id !== null ? ($lots[$l->lot_id] ?? null) : null,
                'qty_requested' => $l->qty_requested,
                'qty_sent' => $l->qty_sent,
                'qty_received' => $l->qty_received,
                'qty_discrepancy' => $l->qty_sent - $l->qty_received,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $variantIds
     * @param  array<mixed>  $lotIds
     * @return array{0: array<string, array<string, string>>, 1: array<string, string>}
     */
    private function lookups(array $variantIds, array $lotIds): array
    {
        $variants = array_map(fn ($v) => $v->toArray(), $this->catalog->describe(array_values(array_unique(array_map('strval', $variantIds)))));
        $lots = $lotIds === [] ? [] : $this->db->table('stock_lots')->where('company_id', app(TenantContext::class)->requireCompanyId())->whereIn('id', array_values(array_unique($lotIds)))->pluck('lot_number', 'id')->map(fn ($n) => (string) $n)->all();

        return [$variants, $lots];
    }
}
