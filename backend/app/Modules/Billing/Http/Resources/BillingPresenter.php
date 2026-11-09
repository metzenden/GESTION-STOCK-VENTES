<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Infrastructure\Models\CreditApplication;
use App\Modules\Billing\Infrastructure\Models\CreditNote;
use App\Modules\Billing\Infrastructure\Models\CreditNoteLine;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use App\Modules\Billing\Infrastructure\Models\InvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final class BillingPresenter
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function invoice(Invoice $i, bool $detailed = true): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $data = [
            'id' => $i->id,
            'number' => $i->number,
            'sales_order_id' => $i->sales_order_id,
            'customer_id' => $i->customer_id,
            'customer' => ['code' => $i->customer_snapshot['code'] ?? null, 'name' => $i->customer_snapshot['name'] ?? null, 'phone' => $i->customer_snapshot['phone'] ?? null],
            'issue_date' => $i->issue_date->toDateString(),
            'due_date' => $i->due_date->toDateString(),
            'currency' => $i->currency,
            'subtotal' => $i->subtotal,
            'discount_total' => $i->discount_total,
            'tax_total' => $i->tax_total,
            'total' => $i->total,
            'amount_paid' => $i->amount_paid,
            'amount_credited' => $i->amount_credited,
            'balance_due' => $i->balance_due,
            'payment_method' => $i->payment_method,
            'payment_status' => $i->payment_status,
            'status' => $i->status,
            // D-058 : « en retard » est calculé, jamais stocké.
            'is_overdue' => $i->status === 'issued' && $i->payment_status !== 'paid' && $i->due_date->toDateString() < $today,
            'created_at' => $i->created_at->toIso8601ZuluString(),
        ];

        if ($detailed) {
            $data['customer_snapshot'] = $i->customer_snapshot;
            $data['company_snapshot'] = $i->company_snapshot;
            $data['lines'] = array_values($i->lines()->get()->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'sales_order_line_id' => $l->sales_order_line_id, 'position' => $l->position, 'description' => $l->description,
                'unit_label' => $l->unit_label, 'qty_units' => $l->qty_units, 'unit_price' => $l->unit_price, 'discount_amount' => $l->discount_amount,
                'tax_rate' => $l->tax_rate, 'tax_amount' => $l->tax_amount, 'line_total' => $l->line_total,
            ])->all());
            $data['payments'] = array_values($this->db->table('customer_payments')->where('company_id', $i->company_id)->where('invoice_id', $i->id)
                ->orderBy('paid_at')->orderBy('id')->get()->map(fn ($p) => [
                    'id' => $p->id, 'number' => $p->number, 'method' => $p->method, 'amount' => (string) $p->amount, 'status' => $p->status,
                    'reverses_payment_id' => $p->reverses_payment_id, 'paid_at' => CarbonImmutable::parse((string) $p->paid_at, 'UTC')->toIso8601ZuluString(),
                ])->all());
            $data['credit_applications'] = array_values(CreditApplication::query()->where('invoice_id', $i->id)->orderBy('applied_at')->get()
                ->map(fn (CreditApplication $a) => ['credit_note_id' => $a->credit_note_id, 'amount' => $a->amount, 'applied_at' => $a->applied_at->toIso8601ZuluString()])->all());
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function creditNote(CreditNote $n, bool $detailed = true): array
    {
        $data = [
            'id' => $n->id,
            'number' => $n->number,
            'customer_id' => $n->customer_id,
            'origin_invoice_id' => $n->origin_invoice_id,
            'return_id' => $n->return_id,
            'kind' => $n->kind,
            'reason' => $n->reason,
            'currency' => $n->currency,
            'total' => $n->total,
            'amount_applied' => $n->amount_applied,
            'amount_available' => $n->amount_available,
            'status' => $n->status,
            'issued_at' => $n->issued_at?->toIso8601ZuluString(),
            'version' => $n->version,
            'created_at' => $n->created_at->toIso8601ZuluString(),
        ];

        if ($detailed) {
            $data['lines'] = array_values($n->lines()->get()->map(fn (CreditNoteLine $l) => [
                'id' => $l->id, 'sales_order_line_id' => $l->sales_order_line_id, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit_price' => $l->unit_price, 'tax_amount' => $l->tax_amount, 'line_total' => $l->line_total,
            ])->all());
            $data['applications'] = array_values(CreditApplication::query()->where('credit_note_id', $n->id)->orderBy('applied_at')->get()
                ->map(fn (CreditApplication $a) => ['invoice_id' => $a->invoice_id, 'amount' => $a->amount, 'applied_at' => $a->applied_at->toIso8601ZuluString()])->all());
        }

        return $data;
    }
}
