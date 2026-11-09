<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\PurchaseOrderService;
use App\Modules\Procurement\Http\Resources\ProcurementPresenter;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrder;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Commandes d'achat (docs/05 §2.5). */
final class PurchaseOrderController
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly ProcurementPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:draft,confirmed,partially_received,received,closed,cancelled'],
            'filter.supplier_id' => ['sometimes', 'string', 'size:26'],
            'filter.q' => ['sometimes', 'string', 'max:64'],
        ]);
        $filters = $validated['filter'] ?? [];
        $query = PurchaseOrder::query()->with('supplier')->orderByDesc('order_date')->orderByDesc('created_at')->orderByDesc('id');

        foreach (['status', 'supplier_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('supplier_reference', 'like', $term));
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (PurchaseOrder $o) => $this->presenter->purchaseOrder($o, false));
    }

    public function show(string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->purchaseOrder(PurchaseOrder::query()->with('supplier')->findOrFail($order))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(self::headerRules(true) + self::lineRules(true));
        $order = $this->orders->create($validated, self::lines($validated), self::actor($request));

        return new JsonResponse(['data' => $this->presenter->purchaseOrder($order)], 201);
    }

    public function update(Request $request, string $order): JsonResponse
    {
        $id = PurchaseOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(self::headerRules(false) + self::lineRules(false) + ['version' => ['sometimes', 'integer', 'min:1']]);
        $header = array_diff_key($validated, ['lines' => true, 'version' => true]);
        $updated = $this->orders->update($id, $header, isset($validated['lines']) ? self::lines($validated) : null,
            isset($validated['version']) ? (int) $validated['version'] : null, self::actor($request));

        return new JsonResponse(['data' => $this->presenter->purchaseOrder($updated)]);
    }

    public function confirm(Request $request, string $order): JsonResponse
    {
        $id = PurchaseOrder::query()->findOrFail($order)->id;

        return new JsonResponse(['data' => $this->presenter->purchaseOrder($this->orders->confirm($id, self::actor($request)))]);
    }

    public function close(Request $request, string $order): JsonResponse
    {
        $id = PurchaseOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(['release_remaining' => ['sometimes', 'boolean']]);

        return new JsonResponse(['data' => $this->presenter->purchaseOrder($this->orders->close($id, (bool) ($validated['release_remaining'] ?? false), self::actor($request)))]);
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $id = PurchaseOrder::query()->findOrFail($order)->id;

        return new JsonResponse(['data' => $this->presenter->purchaseOrder($this->orders->cancel($id, self::actor($request)))]);
    }

    /** @return array<string, list<string>> */
    private static function headerRules(bool $creating): array
    {
        return [
            'supplier_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'order_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estimated_rate' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,10})?$/', 'not_in:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, list<string>> */
    private static function lineRules(bool $required): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:1000'],
            'lines.*.variant_id' => ['required', 'string', 'size:26'],
            'lines.*.unit_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'lines.*.qty_units' => ['required', 'integer', 'min:1', 'max:100000000'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{variant_id: string, unit_id: string|null, qty_units: int, unit_price: string}>
     */
    private static function lines(array $validated): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $validated['lines'];

        return array_map(fn (array $l) => [
            'variant_id' => (string) $l['variant_id'],
            'unit_id' => isset($l['unit_id']) ? (string) $l['unit_id'] : null,
            'qty_units' => (int) $l['qty_units'],
            'unit_price' => (string) $l['unit_price'],
        ], $lines);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
