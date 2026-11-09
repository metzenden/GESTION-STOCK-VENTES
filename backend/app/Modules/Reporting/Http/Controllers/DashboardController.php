<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\OperationalDashboard;
use App\Modules\Reporting\Application\Reports;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Tableau de bord, alertes, rapports, exports, recherche globale et consolidation (L6-04 à L6-09). */
final class DashboardController
{
    public function dashboard(OperationalDashboard $dashboard, TenantContext $tenant, CompanyDirectory $companies): JsonResponse
    {
        $tz = $companies->find($tenant->requireCompanyId())->timezone ?? 'Africa/Dakar';

        return new JsonResponse(['data' => $dashboard->summary(CarbonImmutable::now($tz))]);
    }

    public function alerts(OperationalDashboard $dashboard): JsonResponse
    {
        return new JsonResponse(['data' => $dashboard->alerts(CarbonImmutable::now())]);
    }

    public function catalog(): JsonResponse
    {
        $list = [];

        foreach (Reports::CATALOG as $code => [$label, $permission, $columns]) {
            if (Gate::allows($permission)) {
                $list[] = ['code' => $code, 'label' => $label, 'columns' => $columns];
            }
        }

        return new JsonResponse(['data' => $list]);
    }

    /** TEST-017 : la permission du rapport s'applique aussi bien à l'API qu'à l'export. */
    public function report(Request $request, string $report, Reports $reports): JsonResponse|Response
    {
        abort_unless(isset(Reports::CATALOG[$report]), 404);
        abort_unless(Gate::allows(Reports::CATALOG[$report][1]), 403);
        $v = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'], 'format' => ['sometimes', 'in:json,csv']]);
        $to = isset($v['to']) ? CarbonImmutable::parse($v['to']) : CarbonImmutable::now();
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from']) : $to->startOfMonth();

        if (($v['format'] ?? 'json') === 'csv') {
            return new Response($reports->csv($report, $from, $to), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$report}-{$from->toDateString()}-{$to->toDateString()}.csv\"",
            ]);
        }

        return new JsonResponse(['data' => $reports->rows($report, $from, $to), 'meta' => ['report' => $report, 'label' => Reports::CATALOG[$report][0], 'from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }

    /** L6-09 : recherche globale (produits, clients, documents) dans l'entreprise active. */
    public function search(Request $request, ConnectionInterface $db, TenantContext $tenant): JsonResponse
    {
        $v = $request->validate(['q' => ['required', 'string', 'min:2', 'max:64']]);
        $c = $tenant->requireCompanyId();
        $term = '%'.addcslashes((string) $v['q'], '%_\\').'%';
        $results = [];

        if (Gate::allows('products.view')) {
            foreach ($db->table('products')->where('company_id', $c)->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('reference', 'like', $term))->limit(8)->get(['id', 'reference', 'name']) as $r) {
                $results[] = ['type' => 'product', 'id' => $r->id, 'label' => "{$r->reference} — {$r->name}", 'link' => "/produits/{$r->id}"];
            }
        }

        if (Gate::allows('customers.view')) {
            foreach ($db->table('customers')->where('company_id', $c)->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('code', 'like', $term))->limit(8)->get(['id', 'name', 'phone']) as $r) {
                $results[] = ['type' => 'customer', 'id' => $r->id, 'label' => $r->name.($r->phone !== null ? " ({$r->phone})" : ''), 'link' => '/clients'];
            }
        }

        $docs = [
            ['invoices', 'invoice', 'invoices.view', '/factures/'],
            ['sales_orders', 'sales_order', 'sales.create', '/commandes/'],
            ['purchase_orders', 'purchase_order', 'purchase_orders.view', '/achats/commandes/'],
            ['shipments', 'shipment', 'shipments.view', '/arrivages/'],
        ];

        foreach ($docs as [$table, $type, $permission, $link]) {
            if (Gate::allows($permission)) {
                foreach ($db->table($table)->where('company_id', $c)->where('number', 'like', $term)->limit(5)->get(['id', 'number']) as $r) {
                    $results[] = ['type' => $type, 'id' => $r->id, 'label' => (string) $r->number, 'link' => $link.$r->id];
                }
            }
        }

        return new JsonResponse(['data' => $results]);
    }

    /** L6-07 : vue consolidée du super-administrateur, par devise, sans conversion implicite (TEST-023). */
    public function consolidated(Request $request, ConnectionInterface $db): JsonResponse
    {
        $v = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $to = (string) ($v['to'] ?? CarbonImmutable::now()->toDateString());
        $from = (string) ($v['from'] ?? CarbonImmutable::parse($to)->startOfMonth()->toDateString());
        $rows = $db->table('invoices as i')->join('companies as c', 'c.id', '=', 'i.company_id')
            ->whereBetween('i.issue_date', [$from, $to])->groupBy('c.id', 'c.code', 'c.trade_name', 'i.currency')->orderBy('c.code')
            ->selectRaw('c.id AS company_id, c.code, c.trade_name, i.currency, COUNT(*) AS invoices, SUM(i.total) AS invoiced, SUM(i.balance_due) AS receivables')->get();
        $sum = fn ($g, string $field) => (string) $g->reduce(fn (BigDecimal $c, $r) => $c->plus((string) $r->{$field}), BigDecimal::zero())->toScale(4);
        $totals = $rows->groupBy('currency')->map(fn ($g, $cur) => ['currency' => (string) $cur, 'invoiced' => $sum($g, 'invoiced'), 'receivables' => $sum($g, 'receivables')])->values()->all();

        return new JsonResponse(['data' => [
            'period' => ['from' => $from, 'to' => $to],
            'companies' => array_values($rows->map(fn ($r) => (array) $r)->all()),
            'totals_by_currency' => $totals,
        ]]);
    }
}
