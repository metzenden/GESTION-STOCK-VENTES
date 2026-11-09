<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\DeliveryService;
use App\Modules\Sales\Http\Resources\SalesPresenter;
use App\Modules\Sales\Infrastructure\Models\Delivery;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Livraisons et retraits (docs/05 §2.6). */
final class DeliveryController
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly SalesPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:preparing,ready,in_delivery,delivered,failed,cancelled'],
            'filter.mode' => ['sometimes', 'in:pickup,delivery'],
            'filter.sales_order_id' => ['sometimes', 'string', 'size:26'],
        ]);
        $query = Delivery::query()->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Delivery $d) => $this->presenter->delivery($d, false));
    }

    public function forOrder(string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;

        return new JsonResponse(['data' => array_values(Delivery::query()->where('sales_order_id', $id)->orderBy('created_at')->get()
            ->map(fn (Delivery $d) => $this->presenter->delivery($d))->all())]);
    }

    public function show(string $delivery): JsonResponse
    {
        return $this->respond(Delivery::query()->findOrFail($delivery));
    }

    public function store(Request $request, string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;
        $validated = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*' => ['integer', 'min:0'],
            'mode' => ['sometimes', 'in:pickup,delivery'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'recipient_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'courier' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        /** @var array<string, int>|null $lines */
        $lines = isset($validated['lines']) ? array_map('intval', $validated['lines']) : null;

        return $this->respond($this->deliveries->create($id, $lines, array_diff_key($validated, ['lines' => true]), SalesRequest::actor($request)), 201);
    }

    public function ready(Request $request, string $delivery): JsonResponse
    {
        return $this->respond($this->deliveries->ready($this->find($delivery), SalesRequest::actor($request)));
    }

    public function ship(Request $request, string $delivery): JsonResponse
    {
        $id = $this->find($delivery);
        $validated = $request->validate(['courier' => ['sometimes', 'nullable', 'string', 'max:120']] + self::allocationRules());

        return $this->respond($this->deliveries->ship($id, self::allocations($validated), $validated['courier'] ?? null, SalesRequest::actor($request)));
    }

    public function deliver(Request $request, string $delivery): JsonResponse
    {
        $id = $this->find($delivery);
        $validated = $request->validate(['received' => ['sometimes', 'array'], 'received.*' => ['integer', 'min:0']] + self::allocationRules());
        /** @var array<string, int> $received */
        $received = array_map('intval', $validated['received'] ?? []);

        return $this->respond($this->deliveries->deliver($id, $received, self::allocations($validated), SalesRequest::actor($request)));
    }

    public function fail(Request $request, string $delivery): JsonResponse
    {
        $id = $this->find($delivery);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->respond($this->deliveries->fail($id, $validated['reason'], SalesRequest::actor($request)));
    }

    public function returnToWarehouse(Request $request, string $delivery): JsonResponse
    {
        return $this->respond($this->deliveries->returnToWarehouse($this->find($delivery), SalesRequest::actor($request)));
    }

    public function cancel(Request $request, string $delivery): JsonResponse
    {
        return $this->respond($this->deliveries->cancel($this->find($delivery), SalesRequest::actor($request)));
    }

    /** @return array<string, list<string>> */
    private static function allocationRules(): array
    {
        return ['allocations' => ['sometimes', 'array'], 'allocations.*' => ['array'], 'allocations.*.*' => ['integer', 'min:1']];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array<string, int>>
     */
    private static function allocations(array $validated): array
    {
        /** @var array<string, array<string, int|string>> $raw */
        $raw = $validated['allocations'] ?? [];

        return array_map(fn (array $lots) => array_map('intval', $lots), $raw);
    }

    private function find(string $id): string
    {
        return Delivery::query()->findOrFail($id)->id;
    }

    private function respond(Delivery $delivery, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->delivery($delivery)], $status);
    }
}
