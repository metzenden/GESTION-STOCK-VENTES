<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Resources;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Costing\Infrastructure\Models\LandedCostCalculation;
use App\Modules\Costing\Infrastructure\Models\LandedCostCategory;
use App\Modules\Costing\Infrastructure\Models\LandedCostModel;
use App\Modules\Costing\Infrastructure\Models\ShipmentCost;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use Illuminate\Database\ConnectionInterface;

final class CostingPresenter
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VariantCatalog $catalog,
        private readonly SupplierDirectory $suppliers,
    ) {}

    /** @return array<string, mixed> */
    public function category(LandedCostCategory $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'label' => $c->label, 'is_system' => $c->is_system, 'default_allocation' => $c->default_allocation, 'is_active' => $c->is_active];
    }

    /** @return array<string, mixed> */
    public function cost(ShipmentCost $c): array
    {
        $supplier = $c->supplier_id !== null ? $this->suppliers->find($c->supplier_id) : null;

        return [
            'id' => $c->id,
            'shipment_id' => $c->shipment_id,
            'category_id' => $c->category_id,
            'supplier' => $supplier?->toArray(),
            'description' => $c->description,
            'currency' => $c->currency,
            'amount' => $c->amount,
            'exchange_rate' => $c->exchange_rate,
            'amount_reference' => $c->amount_reference,
            'allocation_method' => $c->allocation_method,
            'target_shipment_line_id' => $c->target_shipment_line_id,
            'is_estimate' => $c->is_estimate,
            'supplier_bill_id' => $c->supplier_bill_id,
            'status' => $c->status,
            'version' => $c->version,
            'created_at' => $c->created_at->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function model(LandedCostModel $m): array
    {
        return [
            'id' => $m->id,
            'code' => $m->code,
            'name' => $m->name,
            'version' => $m->version,
            'is_standard' => $m->is_standard,
            'variables' => $m->variables,
            'expression' => $m->expression,
            'active_from' => $m->active_from?->toDateString(),
            'status' => $m->status,
            'tested_at' => $m->tested_at?->toIso8601ZuluString(),
            'created_at' => $m->created_at->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function calculation(LandedCostCalculation $c, bool $detailed = true): array
    {
        $data = [
            'id' => $c->id,
            'shipment_id' => $c->shipment_id,
            'model_id' => $c->model_id,
            'revision' => $c->revision,
            'status' => $c->status,
            'reference_currency' => $c->reference_currency,
            'rates_snapshot' => $c->rates_snapshot,
            'inputs_hash' => $c->inputs_hash,
            'total_cbm' => $c->total_cbm,
            'total_common_fees' => $c->total_common_fees,
            'total_direct_fees' => $c->total_direct_fees,
            'total_purchase' => $c->total_purchase,
            'total_cost' => $c->total_cost,
            'blocking_issues' => $c->blocking_issues ?? [],
            'discrepancies' => $c->discrepancies ?? [],
            'computed_at' => $c->computed_at?->toIso8601ZuluString(),
            'finalized_at' => $c->finalized_at?->toIso8601ZuluString(),
            'created_at' => $c->created_at->toIso8601ZuluString(),
        ];

        if (! $detailed) {
            return $data;
        }

        $lines = $this->db->table('landed_cost_lines')->where('company_id', $c->company_id)->where('calculation_id', $c->id)->orderBy('created_at')->orderBy('id')->get();
        $allocations = $this->db->table('landed_cost_allocations')->where('company_id', $c->company_id)
            ->whereIn('calculation_line_id', $lines->pluck('id'))->orderBy('id')->get()->groupBy('calculation_line_id');
        $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));

        $data['lines'] = $lines->map(fn ($l) => [
            'id' => $l->id,
            'shipment_line_id' => $l->shipment_line_id,
            'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
            'qty_accepted' => (int) $l->qty_accepted,
            'pieces_per_carton' => $l->pieces_per_carton === null ? null : (int) $l->pieces_per_carton,
            'line_cbm' => (string) $l->line_cbm,
            'purchase_currency' => $l->purchase_currency,
            'purchase_amount_foreign' => (string) $l->purchase_amount_foreign,
            'exchange_rate' => (string) $l->exchange_rate,
            'purchase_amount_converted' => (string) $l->purchase_amount_converted,
            'allocated_common_fees' => (string) $l->allocated_common_fees,
            'direct_fees' => (string) $l->direct_fees,
            'rounding_adjustment' => (string) $l->rounding_adjustment,
            'total_cost' => (string) $l->total_cost,
            'unit_cost_piece' => (string) $l->unit_cost_piece,
            'unit_cost_carton' => $l->unit_cost_carton === null ? null : (string) $l->unit_cost_carton,
            'allocations' => $allocations->get($l->id, collect())->map(fn ($a) => ['shipment_cost_id' => $a->shipment_cost_id, 'amount' => (string) $a->amount])->values()->all(),
        ])->values()->all();

        $data['valuation_adjustments'] = $this->db->table('stock_valuation_adjustments')->where('company_id', $c->company_id)->where('calculation_id', $c->id)
            ->orderBy('id')->get()->map(fn ($a) => [
                'id' => $a->id,
                'lot_id' => $a->lot_id,
                'stock_movement_id' => $a->stock_movement_id,
                'movement_type' => $a->movement_type,
                'quantity' => (int) $a->quantity,
                'old_unit_cost' => (string) $a->old_unit_cost,
                'new_unit_cost' => (string) $a->new_unit_cost,
                'delta_amount' => (string) $a->delta_amount,
            ])->values()->all();

        return $data;
    }
}
