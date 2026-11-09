<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Http\Controllers;

use App\Modules\Logistics\Application\GoodsReceiptService;
use App\Modules\Logistics\Http\Resources\LogisticsPresenter;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceipt;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Réceptions : saisie manuelle, scan, validation (docs/05 §2.5). */
final class GoodsReceiptController
{
    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly LogisticsPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:draft,counting,validated,cancelled'],
            'filter.shipment_id' => ['sometimes', 'string', 'size:26'],
            'filter.purchase_order_id' => ['sometimes', 'string', 'size:26'],
            'filter.warehouse_id' => ['sometimes', 'string', 'size:26'],
        ]);
        $query = GoodsReceipt::query()->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (GoodsReceipt $r) => $this->presenter->receipt($r, false));
    }

    public function show(string $receipt): JsonResponse
    {
        return $this->respond(GoodsReceipt::query()->findOrFail($receipt));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['required_without:purchase_order_id', 'prohibits:purchase_order_id', 'string', 'size:26'],
            'purchase_order_id' => ['required_without:shipment_id', 'string', 'size:26'],
            'warehouse_id' => ['required', 'string', 'size:26'],
            'counting_mode' => ['sometimes', 'in:manual,scan'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $mode = $validated['counting_mode'] ?? 'manual';
        $note = $validated['note'] ?? null;

        $receipt = isset($validated['shipment_id'])
            ? $this->receipts->createForShipment(Shipment::query()->findOrFail((string) $validated['shipment_id'])->id, $validated['warehouse_id'], $mode, $note, self::actor($request))
            : $this->receipts->createForPurchaseOrder($validated['purchase_order_id'], $validated['warehouse_id'], $mode, $note, self::actor($request));

        return $this->respond($receipt, 201);
    }

    public function start(Request $request, string $receipt): JsonResponse
    {
        return $this->respond($this->receipts->startCounting($this->find($receipt), self::actor($request)));
    }

    public function count(Request $request, string $receipt, string $line): JsonResponse
    {
        $id = $this->find($receipt);
        $validated = $request->validate([
            'qty_accepted' => ['required', 'integer', 'min:0', 'max:100000000'],
            'qty_damaged' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'damaged_disposition' => ['sometimes', 'in:hold,reject'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $this->receipts->count($id, $line, (int) $validated['qty_accepted'], (int) ($validated['qty_damaged'] ?? 0),
            $validated['damaged_disposition'] ?? 'hold', $validated['note'] ?? null, self::actor($request));

        return $this->respond(GoodsReceipt::query()->findOrFail($id));
    }

    public function scan(Request $request, string $receipt): JsonResponse
    {
        $id = $this->find($receipt);
        $validated = $request->validate([
            'client_event_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:64'],
            'units' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'damaged' => ['sometimes', 'boolean'],
            'scanned_at' => ['sometimes', 'date'],
        ]);
        $actor = (string) self::actor($request);
        $result = $this->receipts->scan($id, $validated['client_event_id'], $validated['code'], (int) ($validated['units'] ?? 1),
            (bool) ($validated['damaged'] ?? false), isset($validated['scanned_at']) ? CarbonImmutable::parse($validated['scanned_at']) : null, $actor);

        return new JsonResponse(['data' => $this->presenter->scan($result['event']), 'meta' => ['duplicate' => $result['duplicate']]], $result['duplicate'] ? 200 : 201);
    }

    public function validate(Request $request, string $receipt): JsonResponse
    {
        $id = $this->find($receipt);

        return $this->respond($this->receipts->validate($id, Gate::allows('goods_receipts.accept_surplus'), self::actor($request)));
    }

    public function cancel(Request $request, string $receipt): JsonResponse
    {
        return $this->respond($this->receipts->cancel($this->find($receipt), self::actor($request)));
    }

    private function find(string $id): string
    {
        return GoodsReceipt::query()->findOrFail($id)->id;
    }

    private function respond(GoodsReceipt $receipt, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->receipt($receipt)], $status);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
