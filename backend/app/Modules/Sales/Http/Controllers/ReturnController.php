<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\ReturnService;
use App\Modules\Sales\Http\Resources\SalesPresenter;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Sales\Infrastructure\Models\SalesReturn;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Retours clients (docs/05 §2.6). */
final class ReturnController
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly SalesPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['filter.status' => ['sometimes', 'in:draft,received,inspected,closed,cancelled'], 'filter.sales_order_id' => ['sometimes', 'string', 'size:26']]);
        $query = SalesReturn::query()->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (SalesReturn $r) => $this->presenter->salesReturn($r, false));
    }

    public function show(string $return): JsonResponse
    {
        return $this->respond(SalesReturn::query()->findOrFail($return));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sales_order_id' => ['required', 'string', 'size:26'],
            'return_type' => ['required', 'in:refused,defective,exchange,other'],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer', 'min:1'],
        ]);
        $order = SalesOrder::query()->findOrFail((string) $validated['sales_order_id']);
        /** @var array<string, int> $lines */
        $lines = array_map('intval', $validated['lines']);

        return $this->respond($this->returns->create($order->id, $validated['return_type'], $validated['warehouse_id'] ?? null, $lines, $validated['reason'] ?? null, SalesRequest::actor($request)), 201);
    }

    public function receive(Request $request, string $return): JsonResponse
    {
        return $this->respond($this->returns->receive($this->find($return), SalesRequest::actor($request)));
    }

    public function inspect(Request $request, string $return): JsonResponse
    {
        $id = $this->find($return);
        $validated = $request->validate(['decisions' => ['required', 'array'], 'decisions.*' => ['in:restock,damaged']]);
        /** @var array<string, string> $decisions */
        $decisions = $validated['decisions'];

        return $this->respond($this->returns->inspect($id, $decisions, SalesRequest::actor($request)));
    }

    public function close(Request $request, string $return): JsonResponse
    {
        return $this->respond($this->returns->close($this->find($return), SalesRequest::actor($request)));
    }

    public function cancel(Request $request, string $return): JsonResponse
    {
        return $this->respond($this->returns->cancel($this->find($return), SalesRequest::actor($request)));
    }

    private function find(string $id): string
    {
        return SalesReturn::query()->findOrFail($id)->id;
    }

    private function respond(SalesReturn $return, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->salesReturn($return)], $status);
    }
}
