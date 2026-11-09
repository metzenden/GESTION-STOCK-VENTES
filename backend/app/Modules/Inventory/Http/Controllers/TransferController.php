<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\TransferService;
use App\Modules\Inventory\Http\Resources\StockDocumentPresenter;
use App\Modules\Inventory\Infrastructure\Models\StockTransfer;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Transferts entre dépôts (docs/05 §2.4). */
final class TransferController
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly StockDocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['filter.status' => ['sometimes', 'in:draft,confirmed,in_transit,received,cancelled']]);
        $query = StockTransfer::query()->orderByDesc('created_at')->orderByDesc('id');

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (StockTransfer $t) => $this->presenter->transfer($t, false));
    }

    public function show(string $transfer): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->transfer(StockTransfer::query()->findOrFail($transfer))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'string', 'size:26'],
            'to_warehouse_id' => ['required', 'string', 'size:26'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...self::lineRules(true),
        ]);

        $transfer = $this->transfers->create($validated['from_warehouse_id'], $validated['to_warehouse_id'], self::lines($validated), $validated['note'] ?? null, $this->actor($request));

        return new JsonResponse(['data' => $this->presenter->transfer($transfer)], 201);
    }

    public function update(Request $request, string $transfer): JsonResponse
    {
        $transfer = $this->find($transfer);
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:255'], ...self::lineRules(false)]);
        $updated = $this->transfers->update($transfer, isset($validated['lines']) ? self::lines($validated) : null, $validated['note'] ?? null, $this->actor($request));

        return new JsonResponse(['data' => $this->presenter->transfer($updated)]);
    }

    public function confirm(Request $request, string $transfer): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->transfer($this->transfers->confirm($this->find($transfer), $this->actor($request)))]);
    }

    public function ship(Request $request, string $transfer): JsonResponse
    {
        $transfer = $this->find($transfer);
        $validated = $request->validate([
            'allocations' => ['sometimes', 'array'],
            'allocations.*' => ['array'],
            'allocations.*.*' => ['integer', 'min:1'],
        ]);

        /** @var array<string, array<string, int>> $manual */
        $manual = array_map(fn (array $lots) => array_map('intval', $lots), $validated['allocations'] ?? []);

        return new JsonResponse(['data' => $this->presenter->transfer($this->transfers->ship($transfer, $manual, $this->actor($request)))]);
    }

    public function receive(Request $request, string $transfer): JsonResponse
    {
        $transfer = $this->find($transfer);
        $validated = $request->validate([
            'received' => ['sometimes', 'array'],
            'received.*' => ['integer', 'min:0'],
        ]);

        /** @var array<string, int> $received */
        $received = array_map('intval', $validated['received'] ?? []);

        return new JsonResponse(['data' => $this->presenter->transfer($this->transfers->receive($transfer, $received, $this->actor($request)))]);
    }

    public function cancel(Request $request, string $transfer): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->transfer($this->transfers->cancel($this->find($transfer), $this->actor($request)))]);
    }

    /** @return array<string, list<string>> */
    private static function lineRules(bool $required): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'lines.*.variant_id' => ['required', 'string', 'size:26'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{variant_id: string, quantity: int}>
     */
    private static function lines(array $validated): array
    {
        /** @var list<array{variant_id: string, quantity: int|string}> $lines */
        $lines = $validated['lines'];

        return array_map(fn (array $l) => ['variant_id' => $l['variant_id'], 'quantity' => (int) $l['quantity']], $lines);
    }

    private function find(string $id): string
    {
        return StockTransfer::query()->findOrFail($id)->id;
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
