<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Sales\Infrastructure\Models\Delivery;
use App\Modules\Sales\Infrastructure\Models\DeliveryLine;
use App\Modules\Sales\Infrastructure\Models\DeliveryLineLot;
use App\Modules\Sales\Infrastructure\Models\Dispute;
use App\Modules\Sales\Infrastructure\Models\Quotation;
use App\Modules\Sales\Infrastructure\Models\QuotationLine;
use App\Modules\Sales\Infrastructure\Models\ReturnLine;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Sales\Infrastructure\Models\SalesOrderLine;
use App\Modules\Sales\Infrastructure\Models\SalesReturn;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Gate;

/** Représentations des documents de vente ; coûts visibles seulement avec products.view_sensitive (GEN-005). */
final class SalesPresenter
{
    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly Invoicing $invoicing,
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, mixed> */
    public function customer(Customer $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'customer_type' => $c->customer_type,
            'phone' => $c->phone,
            'email' => $c->email,
            'address' => $c->address,
            'default_price_list_id' => $c->default_price_list_id,
            'payment_terms_days' => $c->payment_terms_days,
            'is_walk_in' => $c->is_walk_in,
            'status' => $c->status,
            'has_history' => $c->has_history,
            'created_at' => $c->created_at->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public function quotation(Quotation $q, bool $withLines = true): array
    {
        $data = $this->header($q) + [
            'issue_date' => $q->issue_date->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(),
            'converted_order_id' => $q->converted_order_id,
        ];

        if ($withLines) {
            $data['lines'] = $this->lines(array_values($q->lines()->get()->all()));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function order(SalesOrder $o, bool $withLines = true): array
    {
        $data = $this->header($o) + [
            'quotation_id' => $o->quotation_id,
            'channel' => $o->channel,
            'fulfilment_mode' => $o->fulfilment_mode,
            'recipient_name' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'delivery_address' => $o->delivery_address,
            'has_dispute' => $o->has_dispute,
            'invoice_id' => $o->invoice_id,
            'confirmed_at' => $o->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $o->cancelled_at?->toIso8601ZuluString(),
            'cancellation_reason' => $o->cancellation_reason,
            'parent_order_id' => $o->parent_order_id,
        ];

        if ($withLines) {
            $data['lines'] = $this->lines(array_values($o->lines()->get()->all()));
            $invoice = $o->invoice_id !== null ? $this->invoicing->find($o->invoice_id) : null;
            $data['invoice'] = $invoice === null ? null : [
                'id' => $invoice->id, 'number' => $invoice->number, 'total' => $invoice->total, 'balance_due' => $invoice->balanceDue,
                'payment_status' => $invoice->paymentStatus, 'payment_method' => $invoice->paymentMethod, 'status' => $invoice->status,
            ];
            $data['deliveries'] = array_values(Delivery::query()->where('sales_order_id', $o->id)->orderBy('created_at')->get()
                ->map(fn (Delivery $d) => $this->delivery($d, false))->all());
            $data['disputes'] = array_values(Dispute::query()->where('sales_order_id', $o->id)->orderBy('opened_at')->get()
                ->map(fn (Dispute $d) => $this->dispute($d))->all());
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function delivery(Delivery $d, bool $withLines = true): array
    {
        $data = [
            'id' => $d->id,
            'number' => $d->number,
            'sales_order_id' => $d->sales_order_id,
            'warehouse_id' => $d->warehouse_id,
            'mode' => $d->mode,
            'status' => $d->status,
            'recipient_name' => $d->recipient_name,
            'recipient_phone' => $d->recipient_phone,
            'address' => $d->address,
            'courier' => $d->courier,
            'prepared_at' => $d->prepared_at?->toIso8601ZuluString(),
            'shipped_at' => $d->shipped_at?->toIso8601ZuluString(),
            'delivered_at' => $d->delivered_at?->toIso8601ZuluString(),
            'failed_at' => $d->failed_at?->toIso8601ZuluString(),
            'failure_reason' => $d->failure_reason,
            'attempt' => $d->attempt,
            'version' => $d->version,
            'created_at' => $d->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $d->lines()->get();
            $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $lots = DeliveryLineLot::query()->whereIn('delivery_line_id', $lines->pluck('id'))->orderBy('id')->get()->groupBy('delivery_line_id');
            $numbers = $this->lotNumbers(array_values(array_map('strval', $lots->flatten()->pluck('lot_id')->unique()->all())));
            $sensitive = Gate::allows('products.view_sensitive');
            $data['lines'] = array_values($lines->map(fn (DeliveryLine $l) => [
                'id' => $l->id,
                'sales_order_line_id' => $l->sales_order_line_id,
                'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
                'quantity' => $l->quantity,
                'qty_delivered' => $l->qty_delivered,
                'lots' => array_values($lots->get($l->id, collect())->map(fn (DeliveryLineLot $x) => array_filter([
                    'lot_id' => $x->lot_id,
                    'lot_number' => $numbers[$x->lot_id] ?? null,
                    'quantity' => $x->quantity,
                    'allocation_method' => $x->allocation_method,
                    'attempt' => $x->attempt,
                    'status' => $x->status,
                    'unit_cost' => $sensitive ? $x->unit_cost : '__redacted__',
                ], fn ($v) => $v !== '__redacted__'))->all()),
            ])->all());
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function salesReturn(SalesReturn $r, bool $withLines = true): array
    {
        $data = [
            'id' => $r->id,
            'number' => $r->number,
            'sales_order_id' => $r->sales_order_id,
            'customer_id' => $r->customer_id,
            'warehouse_id' => $r->warehouse_id,
            'return_type' => $r->return_type,
            'status' => $r->status,
            'credit_note_id' => $r->credit_note_id,
            'exchange_order_id' => $r->exchange_order_id,
            'reason' => $r->reason,
            'received_at' => $r->received_at?->toIso8601ZuluString(),
            'closed_at' => $r->closed_at?->toIso8601ZuluString(),
            'created_at' => $r->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $r->lines()->get();
            $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $numbers = $this->lotNumbers(array_values(array_map('strval', $lines->pluck('lot_id')->unique()->all())));
            $data['lines'] = array_values($lines->map(fn (ReturnLine $l) => [
                'id' => $l->id,
                'sales_order_line_id' => $l->sales_order_line_id,
                'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
                'lot_id' => $l->lot_id,
                'lot_number' => $numbers[$l->lot_id] ?? null,
                'quantity' => $l->quantity,
                'disposition' => $l->disposition,
                'credit_amount' => $l->credit_amount,
            ])->all());
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function dispute(Dispute $d): array
    {
        return [
            'id' => $d->id,
            'sales_order_id' => $d->sales_order_id,
            'status' => $d->status,
            'reason' => $d->reason,
            'resolution' => $d->resolution,
            'opened_at' => $d->opened_at->toIso8601ZuluString(),
            'resolved_at' => $d->resolved_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    private function header(Quotation|SalesOrder $doc): array
    {
        $customer = $doc->customer;

        return [
            'id' => $doc->id,
            'number' => $doc->number,
            'customer' => $customer === null ? null : ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'phone' => $customer->phone],
            'price_list_id' => $doc->price_list_id,
            'warehouse_id' => $doc->warehouse_id,
            'currency' => $doc->currency,
            'global_discount' => $doc->global_discount_type === null ? null : ['type' => $doc->global_discount_type, 'value' => (string) $doc->global_discount_value],
            'subtotal' => $doc->subtotal,
            'discount_total' => $doc->discount_total,
            'tax_total' => $doc->tax_total,
            'total' => $doc->total,
            'status' => $doc->status,
            'notes' => $doc->notes,
            'version' => $doc->version,
            'created_at' => $doc->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  list<QuotationLine|SalesOrderLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(array $lines): array
    {
        $variants = $this->catalog->describe(array_values(array_unique(array_map(fn ($l) => $l->variant_id, $lines))));
        $sensitive = Gate::allows('products.view_sensitive');

        return array_map(function (QuotationLine|SalesOrderLine $l) use ($variants, $sensitive) {
            $row = [
                'id' => $l->id,
                'position' => $l->position,
                'variant' => isset($variants[$l->variant_id]) ? $variants[$l->variant_id]->toArray() : null,
                'product_type' => $l->product_type,
                'unit_id' => $l->unit_id,
                'unit_label' => $l->unit_label,
                'unit_factor' => $l->unit_factor,
                'description' => $l->description,
                'qty_units' => $l->qty_units,
                'qty_base' => $l->qty_base,
                'list_unit_price' => $l->list_unit_price,
                'unit_price' => $l->unit_price,
                'price_overridden' => (bool) $l->price_overridden,
                'discount_type' => $l->discount_type,
                'discount_value' => $l->discount_value,
                'discount_amount' => $l->discount_amount,
                'tax_rate_id' => $l->tax_rate_id,
                'tax_rate' => $l->tax_rate,
                'tax_amount' => $l->tax_amount,
                'line_total' => $l->line_total,
            ];

            if ($l instanceof SalesOrderLine) {
                $row += ['qty_delivered' => $l->qty_delivered, 'qty_returned' => $l->qty_returned, 'qty_cancelled' => $l->qty_cancelled];

                if ($sensitive) {
                    $row['estimated_unit_cost'] = $l->estimated_unit_cost;
                }
            }

            return $row;
        }, $lines);
    }

    /**
     * @param  list<string>  $lotIds
     * @return array<string, string>
     */
    private function lotNumbers(array $lotIds): array
    {
        return $lotIds === [] ? [] : $this->db->table('stock_lots')->where('company_id', $this->tenant->requireCompanyId())
            ->whereIn('id', $lotIds)->pluck('lot_number', 'id')->map(fn ($n) => (string) $n)->all();
    }
}
