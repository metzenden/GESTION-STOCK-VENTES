<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Shared\Contracts\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Rapports (L6-06). Chaque rapport déclare la permission qui le protège ; l'API et les exports appliquent la même
 * vérification (TEST-017). Les montants sont en devise de document ; aucune conversion implicite.
 */
final class Reports
{
    /** rapport → [libellé, permission, colonnes] */
    public const CATALOG = [
        'sales_by_product' => ['Ventes par article (remises)', 'reports.financial', ['reference', 'designation', 'quantite', 'ca_net', 'cout', 'marge']],
        'sales_by_customer' => ['Facturation par client', 'invoices.view', ['code', 'client', 'factures', 'facture', 'regle', 'solde']],
        'receivables_aging' => ['Balance âgée des créances', 'invoices.view', ['facture', 'client', 'echeance', 'jours_retard', 'solde', 'tranche']],
        'stock_valuation' => ['Valorisation du stock par article', 'products.view_sensitive', ['reference', 'designation', 'quantite', 'valeur', 'dont_provisoire']],
        'expenses_by_category' => ['Dépenses par catégorie', 'expenses.manage', ['categorie', 'nombre', 'montant']],
        'stock_movements' => ['Mouvements de stock', 'stock.view', ['date', 'type', 'reference', 'lot', 'quantite']],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    /** @return list<array<string, string|int>> */
    public function rows(string $report, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $c = $this->tenant->requireCompanyId();
        $start = $from->startOfDay()->utc()->format('Y-m-d H:i:s.u');
        $end = $to->endOfDay()->utc()->format('Y-m-d H:i:s.u');

        $rows = match ($report) {
            'sales_by_product' => $this->db->table('stock_movements as m')
                ->join('product_variants as v', fn ($j) => $j->on('v.id', '=', 'm.variant_id')->on('v.company_id', '=', 'm.company_id'))
                ->join('products as p', fn ($j) => $j->on('p.id', '=', 'v.product_id')->on('p.company_id', '=', 'v.company_id'))
                ->leftJoin('delivery_lines as dl', fn ($j) => $j->on('dl.id', '=', 'm.source_line_id')->on('dl.company_id', '=', 'm.company_id'))
                ->leftJoin('sales_order_lines as l', fn ($j) => $j->on('l.id', '=', 'dl.sales_order_line_id')->on('l.company_id', '=', 'dl.company_id'))
                ->where('m.company_id', $c)->where('m.movement_type', 'sale_delivery')->whereBetween('m.occurred_at', [$start, $end])
                ->groupBy('v.sku', 'p.name')->orderBy('v.sku')
                ->selectRaw('v.sku AS reference, p.name AS designation, SUM(-m.quantity) AS quantite, ROUND(SUM((l.line_total - l.tax_amount) * -m.quantity / l.qty_base), 4) AS ca_net, ROUND(SUM(-m.total_cost), 4) AS cout')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'sales_by_customer' => $this->db->table('invoices')->where('company_id', $c)->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('customer_id', 'currency')->orderByRaw('SUM(total) DESC')
                ->selectRaw("MAX(JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, '$.code'))) AS code, MAX(JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, '$.name'))) AS client, COUNT(*) AS factures, SUM(total) AS facture, SUM(amount_paid + amount_credited) AS regle, SUM(balance_due) AS solde")
                ->get()->map(fn ($r) => (array) $r)->all(),
            'receivables_aging' => $this->db->table('invoices')->where('company_id', $c)->where('status', 'issued')->where('balance_due', '>', 0)->orderBy('due_date')
                ->selectRaw("number AS facture, JSON_UNQUOTE(JSON_EXTRACT(customer_snapshot, '$.name')) AS client, due_date AS echeance, GREATEST(DATEDIFF(?, due_date), 0) AS jours_retard, balance_due AS solde", [$to->toDateString()])
                ->get()->map(fn ($r) => (array) $r + ['tranche' => self::bucket((int) $r->jours_retard)])->all(),
            'stock_valuation' => $this->db->table('stock_lot_balances as b')
                ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'b.lot_id')->on('l.company_id', '=', 'b.company_id'))
                ->join('product_variants as v', fn ($j) => $j->on('v.id', '=', 'l.variant_id')->on('v.company_id', '=', 'l.company_id'))
                ->join('products as p', fn ($j) => $j->on('p.id', '=', 'v.product_id')->on('p.company_id', '=', 'v.company_id'))
                ->where('b.company_id', $c)->where('b.qty_on_hand', '>', 0)->groupBy('v.sku', 'p.name')->orderBy('v.sku')
                ->selectRaw("v.sku AS reference, p.name AS designation, SUM(b.qty_on_hand) AS quantite, ROUND(SUM(b.qty_on_hand * l.unit_cost), 4) AS valeur, ROUND(SUM(IF(l.cost_status = 'provisional', b.qty_on_hand * l.unit_cost, 0)), 4) AS dont_provisoire")
                ->get()->map(fn ($r) => (array) $r)->all(),
            'expenses_by_category' => $this->db->table('expenses as e')
                ->join('expense_categories as k', fn ($j) => $j->on('k.id', '=', 'e.category_id')->on('k.company_id', '=', 'e.company_id'))
                ->where('e.company_id', $c)->where('e.status', 'posted')->whereBetween('e.expense_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('k.name')->orderBy('k.name')->selectRaw('k.name AS categorie, COUNT(*) AS nombre, SUM(e.amount) AS montant')
                ->get()->map(fn ($r) => (array) $r)->all(),
            'stock_movements' => $this->db->table('stock_movements as m')
                ->join('product_variants as v', fn ($j) => $j->on('v.id', '=', 'm.variant_id')->on('v.company_id', '=', 'm.company_id'))
                ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'm.lot_id')->on('l.company_id', '=', 'm.company_id'))
                ->where('m.company_id', $c)->whereBetween('m.occurred_at', [$start, $end])->orderBy('m.occurred_at')->limit(20000)
                ->selectRaw('m.occurred_at AS date, m.movement_type AS type, v.sku AS reference, l.lot_number AS lot, m.quantity AS quantite')
                ->get()->map(fn ($r) => (array) $r)->all(),
            default => [],
        };

        if ($report === 'sales_by_product') {
            $rows = array_map(fn (array $r) => ['marge' => (string) BigDecimal::of((string) ($r['ca_net'] ?? '0'))->minus((string) ($r['cout'] ?? '0'))] + $r, $rows);
        }

        $columns = self::CATALOG[$report][2] ?? [];

        return array_values(array_map(fn (array $r) => array_combine($columns, array_map(fn (string $col) => is_int($r[$col] ?? null) ? $r[$col] : (is_scalar($r[$col] ?? null) ? (string) $r[$col] : ''), $columns)), $rows));
    }

    /** CSV UTF-8 (séparateur point-virgule, compatible Excel français). */
    public function csv(string $report, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::CATALOG[$report][2], ';', '"', '');

        foreach ($this->rows($report, $from, $to) as $row) {
            // Neutralise les formules (injection CSV).
            fputcsv($handle, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) === 1 && ! is_numeric($v) ? "'".$v : $v, $row), ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function bucket(int $days): string
    {
        return match (true) {
            $days === 0 => 'non échu',
            $days <= 30 => '1-30 jours',
            $days <= 60 => '31-60 jours',
            $days <= 90 => '61-90 jours',
            default => 'plus de 90 jours',
        };
    }
}
