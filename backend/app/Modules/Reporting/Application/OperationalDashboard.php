<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Shared\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/** Tableau de bord (L6-04) et alertes (L6-05) : lectures agrégées de l'entreprise active. */
final class OperationalDashboard
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly CompanyPreferences $preferences,
    ) {}

    /** @return array<string, mixed> */
    public function summary(CarbonImmutable $today): array
    {
        $c = $this->tenant->requireCompanyId();
        $start = $today->startOfDay()->utc()->format('Y-m-d H:i:s.u');
        $end = $today->endOfDay()->utc()->format('Y-m-d H:i:s.u');

        return [
            'date' => $today->toDateString(),
            'orders_today' => $this->db->table('sales_orders')->where('company_id', $c)->whereBetween('confirmed_at', [$start, $end])->count(),
            'sales_today' => (string) ($this->db->table('invoices')->where('company_id', $c)->where('issue_date', $today->toDateString())->sum('total') ?: '0'),
            'collected_today' => (string) ($this->db->table('customer_payments')->where('company_id', $c)->whereBetween('paid_at', [$start, $end])->sum('amount') ?: '0'),
            'deliveries_to_prepare' => $this->db->table('deliveries')->where('company_id', $c)->whereIn('status', ['preparing', 'ready'])->count(),
            'deliveries_in_progress' => $this->db->table('deliveries')->where('company_id', $c)->where('status', 'in_delivery')->count(),
            'shipments_expected' => $this->db->table('shipments')->where('company_id', $c)->whereIn('status', ['shipped', 'in_transit', 'arrived'])->count(),
            'pending_approvals' => $this->db->table('approval_requests')->where('company_id', $c)->where('status', 'pending')->count(),
            'alerts' => $this->alerts($today),
        ];
    }

    /**
     * L6-05 : stock faible, factures en retard, approbations en attente, lots provisoires anciens, litiges ouverts.
     *
     * @return list<array{code: string, level: string, count: int, message: string, link: string}>
     */
    public function alerts(CarbonImmutable $today): array
    {
        $c = $this->tenant->requireCompanyId();
        $threshold = $this->preferences->lowStockDefaultThreshold();
        $low = $this->db->table('stock_positions as p')
            ->join('product_variants as v', fn ($j) => $j->on('v.id', '=', 'p.variant_id')->on('v.company_id', '=', 'p.company_id'))
            ->where('p.company_id', $c)->whereRaw('p.qty_available <= COALESCE(v.low_stock_threshold, ?)', [$threshold])->count();
        $overdue = $this->db->table('invoices')->where('company_id', $c)->where('status', 'issued')->where('payment_status', '<>', 'paid')->where('due_date', '<', $today->toDateString())->count();
        $pending = $this->db->table('approval_requests')->where('company_id', $c)->where('status', 'pending')->count();
        $provisional = $this->db->table('stock_lots')->where('company_id', $c)->where('cost_status', 'provisional')->where('received_at', '<', $today->subDays(30)->utc()->format('Y-m-d H:i:s'))->count();
        $disputes = $this->db->table('disputes')->where('company_id', $c)->where('status', 'open')->count();

        return array_values(array_filter([
            $low > 0 ? ['code' => 'low_stock', 'level' => 'warning', 'count' => $low, 'message' => "{$low} article(s) en stock faible.", 'link' => '/stock?low=1'] : null,
            $overdue > 0 ? ['code' => 'overdue_invoices', 'level' => 'danger', 'count' => $overdue, 'message' => "{$overdue} facture(s) en retard de paiement.", 'link' => '/creances'] : null,
            $pending > 0 ? ['code' => 'pending_approvals', 'level' => 'warning', 'count' => $pending, 'message' => "{$pending} demande(s) d'approbation en attente.", 'link' => '/approbations'] : null,
            $provisional > 0 ? ['code' => 'provisional_lots', 'level' => 'info', 'count' => $provisional, 'message' => "{$provisional} lot(s) au coût provisoire depuis plus de 30 jours.", 'link' => '/arrivages'] : null,
            $disputes > 0 ? ['code' => 'open_disputes', 'level' => 'warning', 'count' => $disputes, 'message' => "{$disputes} litige(s) ouvert(s).", 'link' => '/commandes'] : null,
        ]));
    }
}
