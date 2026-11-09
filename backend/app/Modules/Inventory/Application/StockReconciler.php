<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use Illuminate\Database\ConnectionInterface;

/**
 * Rapprochement des projections avec leurs sources (L2-10, INV-008) :
 * physique = Σ mouvements, réservé = Σ réservations actives, bloqué = Σ blocages actifs, pour chaque position et chaque lot.
 */
final class StockReconciler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array{company_id: string, kind: string, key: string, expected: int, actual: int}> */
    public function discrepancies(?string $companyId = null): array
    {
        $issues = [];
        $scope = fn ($q, string $alias) => $companyId !== null ? $q->where("{$alias}.company_id", $companyId) : $q;

        $checks = [
            'position.on_hand' => $scope($this->db->table('stock_positions as p'), 'p')
                ->leftJoinSub($this->db->table('stock_movements')->selectRaw('company_id, warehouse_id, variant_id, SUM(quantity) AS total')->groupBy('company_id', 'warehouse_id', 'variant_id'), 'm',
                    fn ($j) => $j->on('m.company_id', '=', 'p.company_id')->on('m.warehouse_id', '=', 'p.warehouse_id')->on('m.variant_id', '=', 'p.variant_id'))
                ->selectRaw("p.company_id, CONCAT(p.warehouse_id, '|', p.variant_id) AS k, COALESCE(m.total, 0) AS expected, p.qty_on_hand AS actual"),
            'position.reserved' => $scope($this->db->table('stock_positions as p'), 'p')
                ->leftJoinSub($this->db->table('stock_reservations')->where('status', 'active')->selectRaw('company_id, warehouse_id, variant_id, SUM(qty_active) AS total')->groupBy('company_id', 'warehouse_id', 'variant_id'), 'r',
                    fn ($j) => $j->on('r.company_id', '=', 'p.company_id')->on('r.warehouse_id', '=', 'p.warehouse_id')->on('r.variant_id', '=', 'p.variant_id'))
                ->selectRaw("p.company_id, CONCAT(p.warehouse_id, '|', p.variant_id) AS k, COALESCE(r.total, 0) AS expected, p.qty_reserved AS actual"),
            'position.blocked' => $scope($this->db->table('stock_positions as p'), 'p')
                ->leftJoinSub($this->db->table('stock_holds')->where('status', 'active')->selectRaw('company_id, warehouse_id, variant_id, SUM(qty_active) AS total')->groupBy('company_id', 'warehouse_id', 'variant_id'), 'h',
                    fn ($j) => $j->on('h.company_id', '=', 'p.company_id')->on('h.warehouse_id', '=', 'p.warehouse_id')->on('h.variant_id', '=', 'p.variant_id'))
                ->selectRaw("p.company_id, CONCAT(p.warehouse_id, '|', p.variant_id) AS k, COALESCE(h.total, 0) AS expected, p.qty_blocked AS actual"),
            'lot.on_hand' => $scope($this->db->table('stock_lot_balances as b'), 'b')
                ->leftJoinSub($this->db->table('stock_movements')->selectRaw('company_id, warehouse_id, lot_id, SUM(quantity) AS total')->groupBy('company_id', 'warehouse_id', 'lot_id'), 'm',
                    fn ($j) => $j->on('m.company_id', '=', 'b.company_id')->on('m.warehouse_id', '=', 'b.warehouse_id')->on('m.lot_id', '=', 'b.lot_id'))
                ->selectRaw("b.company_id, CONCAT(b.warehouse_id, '|', b.lot_id) AS k, COALESCE(m.total, 0) AS expected, b.qty_on_hand AS actual"),
            'position.lots' => $scope($this->db->table('stock_positions as p'), 'p')
                ->leftJoinSub($this->db->table('stock_lot_balances')->selectRaw('company_id, warehouse_id, variant_id, SUM(qty_on_hand) AS total')->groupBy('company_id', 'warehouse_id', 'variant_id'), 'b',
                    fn ($j) => $j->on('b.company_id', '=', 'p.company_id')->on('b.warehouse_id', '=', 'p.warehouse_id')->on('b.variant_id', '=', 'p.variant_id'))
                ->selectRaw("p.company_id, CONCAT(p.warehouse_id, '|', p.variant_id) AS k, COALESCE(b.total, 0) AS expected, p.qty_on_hand AS actual"),
        ];

        foreach ($checks as $kind => $query) {
            foreach ($query->get() as $row) {
                if ((int) $row->expected !== (int) $row->actual) {
                    $issues[] = ['company_id' => (string) $row->company_id, 'kind' => $kind, 'key' => (string) $row->k, 'expected' => (int) $row->expected, 'actual' => (int) $row->actual];
                }
            }
        }

        return $issues;
    }
}
