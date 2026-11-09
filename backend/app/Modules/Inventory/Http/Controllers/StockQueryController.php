<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\ExpectedStockSource;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\ValueObjects\Money;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use App\Modules\Shared\Infrastructure\Money\CurrencyRegistry;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * Consultation du stock (L2-14) : requêtes de lecture dédiées. Les coûts ne sont renvoyés qu'avec
 * la permission products.view_sensitive ; sinon ils sont absents et listés dans meta.redacted (GEN-005).
 */
final class StockQueryController
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly VariantCatalog $catalog,
    ) {}

    public function positions(Request $request, CompanyPreferences $preferences): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.warehouse_id' => ['sometimes', 'string', 'size:26'],
            'filter.variant_id' => ['sometimes', 'string', 'size:26'],
            'filter.low' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $companyId = $this->tenant->requireCompanyId();
        $defaultThreshold = $preferences->lowStockDefaultThreshold();

        // Lecture dédiée (docs/02 §2.2) : jointure sur les variantes pour le seuil, la recherche et le tri.
        $query = $this->db->table('stock_positions as p')
            ->join('product_variants as v', fn ($j) => $j->on('v.id', '=', 'p.variant_id')->on('v.company_id', '=', 'p.company_id'))
            ->join('products as pr', fn ($j) => $j->on('pr.id', '=', 'v.product_id')->on('pr.company_id', '=', 'v.company_id'))
            ->where('p.company_id', $companyId)
            ->selectRaw('p.*, COALESCE(v.low_stock_threshold, ?) AS threshold', [$defaultThreshold])
            ->orderBy('pr.name')->orderBy('v.sku')->orderBy('p.warehouse_id');

        if (isset($validated['filter']['warehouse_id'])) {
            $query->where('p.warehouse_id', $validated['filter']['warehouse_id']);
        }

        if (isset($validated['filter']['variant_id'])) {
            $query->where('p.variant_id', $validated['filter']['variant_id']);
        }

        if ((bool) ($validated['filter']['low'] ?? false)) {
            $query->whereRaw('p.qty_available <= COALESCE(v.low_stock_threshold, ?)', [$defaultThreshold]);
        }

        if (isset($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('pr.name', 'like', $term)->orWhere('pr.reference', 'like', $term)->orWhere('v.sku', 'like', $term));
        }

        $page = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        /** @var list<stdClass> $rows */
        $rows = $page->items();
        $variants = $this->catalog->describe(array_values(array_unique(array_map(fn ($r) => (string) $r->variant_id, $rows))));
        $transit = $this->inTransit(array_map(fn ($r) => (string) $r->variant_id, $rows));
        $expected = $this->expected(array_values(array_unique(array_map(fn ($r) => (string) $r->variant_id, $rows))));

        return Paginated::response($page, function (stdClass $row) use ($variants, $transit, $expected) {
            $available = (int) $row->qty_available;

            return [
                'warehouse_id' => $row->warehouse_id,
                'variant' => ($variants[$row->variant_id] ?? null)?->toArray(),
                'on_hand' => (int) $row->qty_on_hand,
                'reserved' => (int) $row->qty_reserved,
                'blocked' => (int) $row->qty_blocked,
                'in_delivery' => (int) $row->qty_in_delivery,
                'available' => $available,
                // Hors dépôt (jamais vendable, ST-001) : arrivages attendus et transferts en route vers ce dépôt.
                'expected' => $expected["{$row->warehouse_id}|{$row->variant_id}"] ?? 0,
                'in_transit' => $transit["{$row->warehouse_id}|{$row->variant_id}"] ?? 0,
                'low_stock_threshold' => (int) $row->threshold,
                'is_low' => $available <= (int) $row->threshold,
            ];
        });
    }

    public function lots(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.variant_id' => ['sometimes', 'string', 'size:26'],
            'filter.warehouse_id' => ['sometimes', 'string', 'size:26'],
            'filter.with_empty' => ['sometimes', 'boolean'],
        ]);
        $sensitive = Gate::allows('products.view_sensitive');

        $query = $this->db->table('stock_lot_balances as b')
            ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'b.lot_id')->on('l.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $this->tenant->requireCompanyId())
            ->orderBy('l.received_at')->orderBy('l.lot_number')
            ->select('b.warehouse_id', 'b.variant_id', 'b.qty_on_hand', 'b.qty_blocked', 'b.qty_in_delivery', 'l.id', 'l.lot_number', 'l.origin_type', 'l.received_at', 'l.initial_qty', 'l.unit_cost', 'l.cost_currency', 'l.cost_status');

        foreach (['variant_id', 'warehouse_id'] as $field) {
            if (isset($validated['filter'][$field])) {
                $query->where("b.{$field}", $validated['filter'][$field]);
            }
        }

        if (! (bool) ($validated['filter']['with_empty'] ?? false)) {
            $query->where('b.qty_on_hand', '>', 0);
        }

        $response = Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), function (stdClass $row) use ($sensitive) {
            $data = [
                'id' => $row->id,
                'lot_number' => $row->lot_number,
                'warehouse_id' => $row->warehouse_id,
                'variant_id' => $row->variant_id,
                'origin_type' => $row->origin_type,
                'received_at' => CarbonImmutable::parse((string) $row->received_at)->toIso8601ZuluString(),
                'initial_qty' => (int) $row->initial_qty,
                'on_hand' => (int) $row->qty_on_hand,
                'blocked' => (int) $row->qty_blocked,
                'in_delivery' => (int) $row->qty_in_delivery,
                'free' => (int) $row->qty_on_hand - (int) $row->qty_blocked - (int) $row->qty_in_delivery,
                'cost_status' => $row->cost_status,
            ];

            if ($sensitive) {
                $data['unit_cost'] = ['amount' => (string) BigDecimal::of((string) $row->unit_cost)->strippedOfTrailingZeros(), 'currency' => $row->cost_currency];
            }

            return $data;
        });

        return $this->withRedacted($response, $sensitive ? [] : ['unit_cost']);
    }

    public function movements(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.warehouse_id' => ['sometimes', 'string', 'size:26'],
            'filter.variant_id' => ['sometimes', 'string', 'size:26'],
            'filter.lot_id' => ['sometimes', 'string', 'size:26'],
            'filter.movement_type' => ['sometimes', 'string', 'max:24'],
            'filter.date_from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.date_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $sensitive = Gate::allows('products.view_sensitive');
        $currency = app(CompanyDirectory::class)->find($this->tenant->requireCompanyId())->referenceCurrency ?? 'XOF';

        $query = $this->db->table('stock_movements as m')
            ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'm.lot_id')->on('l.company_id', '=', 'm.company_id'))
            ->where('m.company_id', $this->tenant->requireCompanyId())
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')
            ->select('m.*', 'l.lot_number');

        foreach (['warehouse_id', 'variant_id', 'lot_id', 'movement_type'] as $field) {
            if (isset($validated['filter'][$field])) {
                $query->where("m.{$field}", $validated['filter'][$field]);
            }
        }

        if (isset($validated['filter']['date_from'])) {
            $query->where('m.occurred_at', '>=', $validated['filter']['date_from'].' 00:00:00');
        }

        if (isset($validated['filter']['date_to'])) {
            $query->where('m.occurred_at', '<=', $validated['filter']['date_to'].' 23:59:59.999999');
        }

        $response = Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), function (stdClass $row) use ($sensitive, $currency) {
            $data = [
                'id' => $row->id,
                'warehouse_id' => $row->warehouse_id,
                'variant_id' => $row->variant_id,
                'lot_id' => $row->lot_id,
                'lot_number' => $row->lot_number,
                'movement_type' => $row->movement_type,
                'quantity' => (int) $row->quantity,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'reverses_movement_id' => $row->reverses_movement_id,
                'reason' => $row->reason,
                'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601ZuluString(),
            ];

            if ($sensitive) {
                $data['unit_cost'] = ['amount' => (string) BigDecimal::of((string) $row->unit_cost)->strippedOfTrailingZeros(), 'currency' => $currency];
                $data['total_cost'] = Money::of((string) $row->total_cost, app(CurrencyRegistry::class)->of($currency))->rounded()->jsonSerialize();
            }

            return $data;
        });

        return $this->withRedacted($response, $sensitive ? [] : ['unit_cost', 'total_cost']);
    }

    /** Valeur du stock au coût des lots, par dépôt (permission sensible). */
    public function valuation(CompanyDirectory $companies, CurrencyRegistry $currencies): JsonResponse
    {
        $companyId = $this->tenant->requireCompanyId();
        $currency = $currencies->of($companies->find($companyId)->referenceCurrency ?? 'XOF');
        $rows = $this->db->table('stock_lot_balances as b')
            ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'b.lot_id')->on('l.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $companyId)->where('b.qty_on_hand', '>', 0)
            ->get(['b.warehouse_id', 'b.qty_on_hand', 'l.unit_cost', 'l.cost_status']);

        $byWarehouse = [];
        $total = Money::zero($currency);
        $provisional = 0;

        foreach ($rows as $row) {
            $value = Money::of(BigDecimal::of((string) $row->unit_cost)->multipliedBy((int) $row->qty_on_hand), $currency);
            $byWarehouse[$row->warehouse_id] = ($byWarehouse[$row->warehouse_id] ?? Money::zero($currency))->plus($value);
            $total = $total->plus($value);
            $provisional += $row->cost_status === 'provisional' ? 1 : 0;
        }

        return new JsonResponse(['data' => [
            'currency' => $currency->code,
            'total' => $total->rounded()->jsonSerialize(),
            'warehouses' => array_map(fn ($wh, Money $m) => ['warehouse_id' => $wh, 'value' => $m->rounded()->jsonSerialize()], array_keys($byWarehouse), $byWarehouse),
            'provisional_lots' => $provisional,
        ]]);
    }

    /**
     * Quantités en route vers chaque dépôt (transferts expédiés non réceptionnés).
     *
     * @param  list<string>  $variantIds
     * @return array<string, int> « dépôt|variante » → quantité
     */
    private function inTransit(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return $this->db->table('stock_transfer_lines as l')
            ->join('stock_transfers as t', fn ($j) => $j->on('t.id', '=', 'l.transfer_id')->on('t.company_id', '=', 'l.company_id'))
            ->where('t.company_id', $this->tenant->requireCompanyId())->where('t.status', 'in_transit')
            ->whereIn('l.variant_id', $variantIds)
            ->selectRaw("CONCAT(t.to_warehouse_id, '|', l.variant_id) AS k, SUM(l.qty_sent) AS qty")->groupBy('t.to_warehouse_id', 'l.variant_id')
            ->pluck('qty', 'k')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Stock attendu fourni par les modules amont (ExpectedStockSource).
     *
     * @param  list<string>  $variantIds
     * @return array<string, int>
     */
    private function expected(array $variantIds): array
    {
        $total = [];

        if ($variantIds === []) {
            return $total;
        }

        /** @var iterable<ExpectedStockSource> $sources */
        $sources = app()->tagged(ExpectedStockSource::TAG);

        foreach ($sources as $source) {
            foreach ($source->expected($variantIds) as $key => $qty) {
                $total[$key] = ($total[$key] ?? 0) + $qty;
            }
        }

        return $total;
    }

    /** @param list<string> $redacted */
    private function withRedacted(JsonResponse $response, array $redacted): JsonResponse
    {
        /** @var array{data: list<mixed>, meta: array<string, mixed>} $payload */
        $payload = $response->getData(true);
        $payload['meta']['redacted'] = $redacted;

        return $response->setData($payload);
    }
}
