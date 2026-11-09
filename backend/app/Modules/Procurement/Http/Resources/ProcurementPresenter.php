<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrder;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrderLine;
use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Procurement\Infrastructure\Models\SupplierBill;

final class ProcurementPresenter
{
    public function __construct(private readonly VariantCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function supplier(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'supplier_type' => $s->supplier_type,
            'country_code' => $s->country_code,
            'currency' => $s->currency,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'contact_name' => $s->contact_name,
            'notes' => $s->notes,
            'status' => $s->status,
            'has_history' => $s->has_history,
            'created_at' => $s->created_at->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function purchaseOrder(PurchaseOrder $order, bool $withLines = true): array
    {
        $supplier = $order->supplier;
        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'supplier' => $supplier === null ? null : ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name],
            'supplier_reference' => $order->supplier_reference,
            'order_date' => $order->order_date->toDateString(),
            'currency' => $order->currency,
            'estimated_rate' => $order->estimated_rate,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'notes' => $order->notes,
            'confirmed_at' => $order->confirmed_at?->toIso8601ZuluString(),
            'closed_at' => $order->closed_at?->toIso8601ZuluString(),
            'version' => $order->version,
            'created_at' => $order->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $order->lines()->get();
            $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $units = $this->catalog->units(array_values(array_map('strval', $lines->pluck('unit_id')->unique()->all())));
            $data['lines'] = $lines->map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'position' => $l->position,
                'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
                'unit' => isset($units[$l->unit_id]) ? $units[$l->unit_id]->toArray() : null,
                'unit_factor' => $l->unit_factor,
                'qty_units' => $l->qty_units,
                'qty_base' => $l->qty_base,
                'unit_price' => $l->unit_price,
                'line_total' => $l->line_total,
                'qty_received' => $l->qty_received,
            ])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function bill(SupplierBill $bill): array
    {
        $supplier = $bill->supplier;

        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'supplier' => $supplier === null ? null : ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name],
            'supplier_invoice_ref' => $bill->supplier_invoice_ref,
            'description' => $bill->description,
            'source_type' => $bill->source_type,
            'source_id' => $bill->source_id,
            'compensates_bill_id' => $bill->compensates_bill_id,
            'currency' => $bill->currency,
            'amount' => $bill->amount,
            'amount_paid' => $bill->amount_paid,
            'balance' => $bill->balance,
            'bill_date' => $bill->bill_date->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'status' => $bill->status,
            'created_at' => $bill->created_at->toIso8601ZuluString(),
        ];
    }
}
