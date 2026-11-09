<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Http\Controllers;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Http\Resources\LogisticsPresenter;
use App\Modules\Logistics\Infrastructure\Models\Container;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Arrivages, conteneurs et articles attendus (docs/05 §2.5). */
final class ShipmentController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly LogisticsPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:preparing,shipped,in_transit,arrived,unloading,partially_received,received,closed,cancelled'],
            'filter.q' => ['sometimes', 'string', 'max:64'],
        ]);
        $filters = $validated['filter'] ?? [];
        $query = Shipment::query()->orderByDesc('created_at')->orderByDesc('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('reference', 'like', $term)
                ->orWhereIn('id', Container::query()->where('container_number', 'like', $term)->select('shipment_id')));
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Shipment $s) => $this->presenter->shipment($s, false));
    }

    public function show(string $shipment): JsonResponse
    {
        return $this->respond(Shipment::query()->findOrFail($shipment));
    }

    public function store(Request $request): JsonResponse
    {
        $shipment = $this->shipments->create($request->validate(self::headerRules()), self::actor($request));

        return $this->respond($shipment, 201);
    }

    public function update(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate(self::headerRules() + ['version' => ['sometimes', 'integer', 'min:1']]);
        $version = isset($validated['version']) ? (int) $validated['version'] : null;
        unset($validated['version']);

        return $this->respond($this->shipments->update($id, $validated, $version, self::actor($request)));
    }

    public function transition(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate([
            'to' => ['required', 'in:shipped,in_transit,arrived,received,closed,cancelled'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return $this->respond($this->shipments->transition($id, $validated['to'], $validated['date'] ?? null, self::actor($request)));
    }

    public function containers(string $shipment): JsonResponse
    {
        $shipment = Shipment::query()->findOrFail($shipment);

        return new JsonResponse(['data' => $shipment->containers()->get()->map(fn (Container $c) => $this->presenter->container($c))->values()->all()]);
    }

    public function storeContainer(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate([
            'container_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'container_type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'seal_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        return new JsonResponse(['data' => $this->presenter->container($this->shipments->addContainer($id, $validated, self::actor($request)))], 201);
    }

    public function linkPurchaseOrder(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'string', 'size:26'],
            'with_lines' => ['sometimes', 'boolean'],
        ]);

        return $this->respond($this->shipments->linkPurchaseOrder($id, $validated['purchase_order_id'], (bool) ($validated['with_lines'] ?? true), self::actor($request)));
    }

    public function lines(string $shipment): JsonResponse
    {
        $shipment = Shipment::query()->findOrFail($shipment);

        return $this->withRedaction(new JsonResponse(['data' => $this->presenter->lines($shipment->lines()->get())]));
    }

    public function storeLines(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate(['lines' => ['required', 'array', 'min:1', 'max:1000']] + self::lineRules('lines.*.'));
        /** @var list<array<string, mixed>> $lines */
        $lines = $validated['lines'];
        $this->shipments->addLines($id, $lines, self::actor($request));

        return $this->respond(Shipment::query()->findOrFail($id), 201);
    }

    public function updateLine(Request $request, string $shipment, string $line): JsonResponse
    {
        $line = $this->line($shipment, $line);
        $validated = $request->validate(self::lineRules(''));
        $updated = $this->shipments->updateLine($line->id, $validated, self::actor($request));

        return $this->withRedaction(new JsonResponse(['data' => $this->presenter->lines(collect([$updated]))[0]]));
    }

    public function destroyLine(string $shipment, string $line): Response
    {
        $this->shipments->deleteLine($this->line($shipment, $line)->id);

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private static function headerRules(): array
    {
        return [
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:120'],
            'origin_country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'shipped_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'eta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'default_warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function lineRules(string $prefix): array
    {
        $decimal = 'regex:/^\d{1,15}(\.\d{1,8})?$/';

        return [
            "{$prefix}purchase_order_line_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "{$prefix}variant_id" => ['sometimes', 'string', 'size:26'],
            "{$prefix}supplier_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "{$prefix}container_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "{$prefix}destination_warehouse_id" => ['sometimes', 'string', 'size:26'],
            "{$prefix}cartons" => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            "{$prefix}pieces_per_carton" => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}qty_expected" => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            "{$prefix}cbm_total" => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,6})?$/'],
            "{$prefix}cbm_per_carton" => ['sometimes', 'string', 'regex:/^\d{1,8}(\.\d{1,6})?$/'],
            "{$prefix}purchase_currency" => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            "{$prefix}purchase_unit_price" => ['sometimes', 'string', $decimal],
        ];
    }

    private function line(string $shipment, string $line): ShipmentLine
    {
        $shipment = Shipment::query()->findOrFail($shipment);

        return ShipmentLine::query()->where('shipment_id', $shipment->id)->findOrFail($line);
    }

    private function respond(Shipment $shipment, int $status = 200): JsonResponse
    {
        return $this->withRedaction(new JsonResponse(['data' => $this->presenter->shipment($shipment)], $status));
    }

    private function withRedaction(JsonResponse $response): JsonResponse
    {
        if (LogisticsPresenter::canSeePrices()) {
            return $response;
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->getData(true);
        $payload['meta'] = ['redacted' => LogisticsPresenter::PRICE_FIELDS];

        return $response->setData($payload);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
