<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\AdjustmentService;
use App\Modules\Inventory\Http\Resources\StockDocumentPresenter;
use App\Modules\Inventory\Infrastructure\Models\StockAdjustment;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ajustements de stock (docs/05 §2.4). */
final class AdjustmentController
{
    public function __construct(
        private readonly AdjustmentService $adjustments,
        private readonly StockDocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['filter.status' => ['sometimes', 'in:draft,pending_approval,posted,rejected,cancelled']]);
        $query = StockAdjustment::query()->orderByDesc('created_at')->orderByDesc('id');

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (StockAdjustment $a) => $this->presenter->adjustment($a, false));
    }

    public function show(string $adjustment): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->adjustment(StockAdjustment::query()->findOrFail($adjustment))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'string', 'size:26'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...self::lineRules(true),
        ]);

        $adjustment = $this->adjustments->create($validated['warehouse_id'], $validated['reason'] ?? null, self::lines($validated), $this->actor($request));

        return new JsonResponse(['data' => $this->presenter->adjustment($adjustment)], 201);
    }

    public function update(Request $request, string $adjustment): JsonResponse
    {
        $adjustment = StockAdjustment::query()->findOrFail($adjustment)->id;
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255'], ...self::lineRules(false)]);

        $updated = $this->adjustments->update($adjustment, $validated['reason'] ?? null,
            isset($validated['lines']) ? self::lines($validated) : null, self::version($request), $this->actor($request));

        return new JsonResponse(['data' => $this->presenter->adjustment($updated)]);
    }

    public function submit(Request $request, string $adjustment): JsonResponse
    {
        $result = $this->adjustments->submit(StockAdjustment::query()->findOrFail($adjustment)->id, $this->actor($request));

        // 202 : approbation requise, la demande reste en attente (docs/05 §1.6).
        return new JsonResponse(['data' => $this->presenter->adjustment($result)], $result->status === 'pending_approval' ? 202 : 200);
    }

    public function cancel(Request $request, string $adjustment): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->adjustment($this->adjustments->cancel(StockAdjustment::query()->findOrFail($adjustment)->id, $this->actor($request)))]);
    }

    /** @return array<string, list<string>> */
    private static function lineRules(bool $required): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'lines.*.variant_id' => ['required', 'string', 'size:26'],
            'lines.*.lot_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'lines.*.quantity' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'lines.*.kind' => ['sometimes', 'in:gain,loss,damage,correction'],
            'lines.*.unit_cost' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{variant_id: string, lot_id?: string|null, quantity: int, kind?: string, unit_cost?: string|null}>
     */
    private static function lines(array $validated): array
    {
        /** @var list<array{variant_id: string, lot_id?: string|null, quantity: int|string, kind?: string, unit_cost?: string|null}> $lines */
        $lines = $validated['lines'];

        return array_map(fn (array $l) => ['quantity' => (int) $l['quantity']] + $l, $lines);
    }

    public static function version(Request $request): ?int
    {
        $ifMatch = $request->header('If-Match');

        return is_string($ifMatch) && preg_match('/^"?(\d+)"?$/', $ifMatch, $m) === 1 ? (int) $m[1] : null;
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
