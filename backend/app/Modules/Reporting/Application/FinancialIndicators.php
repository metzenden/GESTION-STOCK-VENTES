<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Shared\Contracts\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Indicateurs de gestion (L5-05) — définitions uniques de docs/04 §11.1, en devise de référence.
 * Lecture seule sur les tables des modules (docs/02 §2.2 : module Reporting).
 *
 * - CA net = Σ net HT des lignes × quantité remise / quantité commandée (remises datées en P)
 *            − Σ avoirs de retour et commerciaux HT émis en P (D-018)
 * - CMV    = Σ coût des sorties sale_delivery − Σ coût des return_in + Σ ajustements de valorisation, en P
 * - Charges = Σ dépenses en P + pertes et ajustements négatifs valorisés − ajustements positifs valorisés
 * - Marge = CA net − CMV ; Résultat = Marge − Charges
 */
final class FinancialIndicators
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, mixed> */
    public function compute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $company = $this->tenant->requireCompanyId();
        [$start, $end] = [$from->startOfDay()->utc()->format('Y-m-d H:i:s.u'), $to->endOfDay()->utc()->format('Y-m-d H:i:s.u')];

        // CA de gestion reconnu à la remise, au prorata des quantités remises (D-018).
        $revenue = $this->sum($this->db->table('delivery_lines as dl')
            ->join('deliveries as d', fn ($j) => $j->on('d.id', '=', 'dl.delivery_id')->on('d.company_id', '=', 'dl.company_id'))
            ->join('sales_order_lines as l', fn ($j) => $j->on('l.id', '=', 'dl.sales_order_line_id')->on('l.company_id', '=', 'dl.company_id'))
            ->where('dl.company_id', $company)->where('d.status', 'delivered')->whereBetween('d.delivered_at', [$start, $end])
            ->selectRaw('SUM((l.line_total - l.tax_amount) * dl.qty_delivered / l.qty_base) AS v'));
        // Prestations rendues : rattachées à la date de la première remise de la commande.
        $services = $this->sum($this->db->table('sales_order_lines as l')
            ->join('sales_orders as o', fn ($j) => $j->on('o.id', '=', 'l.sales_order_id')->on('o.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $company)->where('l.product_type', '<>', 'stock')->where('l.qty_delivered', '>', 0)
            ->whereRaw('(SELECT MIN(d.delivered_at) FROM deliveries d WHERE d.company_id = o.company_id AND d.sales_order_id = o.id AND d.status = ?) BETWEEN ? AND ?', ['delivered', $start, $end])
            ->selectRaw('SUM(l.line_total - l.tax_amount) AS v'));
        $credits = $this->sum($this->db->table('credit_note_lines as cl')
            ->join('credit_notes as n', fn ($j) => $j->on('n.id', '=', 'cl.credit_note_id')->on('n.company_id', '=', 'cl.company_id'))
            ->where('cl.company_id', $company)->where('n.status', 'issued')->whereIn('n.kind', ['return', 'commercial', 'price_correction'])
            ->whereBetween('n.issued_at', [$start, $end])->selectRaw('SUM(cl.line_total - cl.tax_amount) AS v'));
        $netRevenue = $revenue->plus($services)->minus($credits);

        $movements = fn (array $types) => $this->sum($this->db->table('stock_movements')->where('company_id', $company)->whereIn('movement_type', $types)
            ->whereBetween('occurred_at', [$start, $end])->selectRaw('SUM(-total_cost) AS v'));
        $valuation = $this->sum($this->db->table('stock_valuation_adjustments')->where('company_id', $company)->whereBetween('created_at', [$start, $end])->selectRaw('SUM(delta_amount) AS v'));
        $cogs = $movements(['sale_delivery'])->plus($movements(['return_in']))->plus($valuation);

        $expenses = $this->sum($this->db->table('expenses')->where('company_id', $company)->where('status', 'posted')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->selectRaw('SUM(amount) AS v'));
        $losses = $movements(['loss', 'adjustment_out']);
        $gains = $movements(['adjustment_in'])->negated();
        $charges = $expenses->plus($losses)->minus($gains);
        $margin = $netRevenue->minus($cogs);

        $collected = $this->sum($this->db->table('customer_payments')->where('company_id', $company)->whereBetween('paid_at', [$start, $end])->selectRaw('SUM(amount) AS v'));
        $invoiced = $this->sum($this->db->table('invoices')->where('company_id', $company)->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->selectRaw('SUM(total) AS v'))
            ->minus($this->sum($this->db->table('credit_notes')->where('company_id', $company)->where('status', 'issued')->whereBetween('issued_at', [$start, $end])->selectRaw('SUM(total) AS v')));
        $receivables = $this->sum($this->db->table('invoices')->where('company_id', $company)->where('status', 'issued')->selectRaw('SUM(balance_due) AS v'));
        $stock = $this->sum($this->db->table('stock_lot_balances as b')
            ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'b.lot_id')->on('l.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $company)->selectRaw('SUM(b.qty_on_hand * l.unit_cost) AS v'));
        $payables = $this->db->table('supplier_bills')->where('company_id', $company)->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, SUM(amount - amount_paid) AS balance')->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'balance' => self::fmt(BigDecimal::of((string) $r->balance))])->values()->all();
        $treasury = $this->db->table('financial_accounts')->where('company_id', $company)->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, SUM(balance) AS balance')->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'balance' => self::fmt(BigDecimal::of((string) $r->balance))])->values()->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'invoiced' => self::fmt($invoiced),
            'net_revenue' => self::fmt($netRevenue),
            'cost_of_goods_sold' => self::fmt($cogs),
            'gross_margin' => self::fmt($margin),
            'charges' => self::fmt($charges),
            'net_result' => self::fmt($margin->minus($charges)),
            'collected' => self::fmt($collected),
            'receivables' => self::fmt($receivables),
            'stock_value' => self::fmt($stock),
            'supplier_payables' => $payables,
            'treasury' => $treasury,
        ];
    }

    private function sum(Builder $query): BigDecimal
    {
        $value = $query->value('v');

        return $value === null ? BigDecimal::zero() : BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp);
    }

    private static function fmt(BigDecimal $v): string
    {
        return (string) $v->toScale(4, RoundingMode::HalfUp);
    }
}
