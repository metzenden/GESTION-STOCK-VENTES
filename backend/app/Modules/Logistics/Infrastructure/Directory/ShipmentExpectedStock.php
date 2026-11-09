<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Directory;

use App\Modules\Inventory\Contracts\ExpectedStockSource;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;

/** ST-001 : articles attendus des arrivages non soldés, par dépôt de destination. */
final class ShipmentExpectedStock implements ExpectedStockSource
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    public function expected(array $variantIds): array
    {
        return $this->db->table('shipment_lines as l')
            ->join('shipments as s', fn ($j) => $j->on('s.id', '=', 'l.shipment_id')->on('s.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $this->tenant->requireCompanyId())
            ->whereIn('s.status', ['preparing', 'shipped', 'in_transit', 'arrived', 'unloading', 'partially_received'])
            ->where('l.is_closed', false)
            ->whereIn('l.variant_id', $variantIds)
            ->whereColumn('l.qty_expected', '>', 'l.qty_received')
            ->selectRaw("CONCAT(l.destination_warehouse_id, '|', l.variant_id) AS k, SUM(l.qty_expected - l.qty_received) AS qty")
            ->groupBy('l.destination_warehouse_id', 'l.variant_id')
            ->pluck('qty', 'k')->map(fn ($v) => (int) $v)->all();
    }
}
