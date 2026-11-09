<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Http\Controllers;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Catalog\Contracts\VariantSummary;
use App\Modules\Logistics\Application\PackingListService;
use App\Modules\Logistics\Http\Resources\LogisticsPresenter;
use App\Modules\Logistics\Infrastructure\Models\PackingList;
use App\Modules\Logistics\Infrastructure\Models\PackingListLine;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Packing lists : téléversement, revue ligne à ligne, validation (docs/05 §2.5). */
final class PackingListController
{
    public function __construct(
        private readonly PackingListService $lists,
        private readonly VariantCatalog $catalog,
    ) {}

    public function index(string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;

        return new JsonResponse(['data' => PackingList::query()->where('shipment_id', $id)->orderByDesc('created_at')->get()
            ->map(fn (PackingList $l) => $this->present($l, false))->values()->all()]);
    }

    public function store(Request $request, string $shipment): JsonResponse
    {
        $id = Shipment::query()->findOrFail($shipment)->id;
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'supplier_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'mapping_template_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'mapping' => ['sometimes', 'array'],
            'mapping.header_row' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'mapping.columns' => ['sometimes', 'array'],
            'mapping.columns.*' => ['string', 'max:120'],
        ]);
        /** @var array{header_row?: int, columns?: array<string, string>}|null $mapping */
        $mapping = $validated['mapping'] ?? null;

        $list = $this->lists->upload($id, $request->file('file'), $validated['supplier_id'] ?? null, $validated['currency'] ?? null,
            $validated['mapping_template_id'] ?? null, $mapping, self::actor($request));

        return new JsonResponse(['data' => $this->present($list)], 201);
    }

    public function show(string $list): JsonResponse
    {
        return new JsonResponse(['data' => $this->present(PackingList::query()->findOrFail($list))]);
    }

    public function lines(string $list): JsonResponse
    {
        return new JsonResponse(['data' => $this->present(PackingList::query()->findOrFail($list))['lines']]);
    }

    public function updateLine(Request $request, string $list, string $line): JsonResponse
    {
        $list = PackingList::query()->findOrFail($list);
        $line = PackingListLine::query()->where('packing_list_id', $list->id)->findOrFail($line);
        $validated = $request->validate([
            'action' => ['sometimes', 'in:confirm,new_product,ignore,reset'],
            'variant_id' => ['sometimes', 'string', 'size:26'],
            'new_product' => ['sometimes', 'array'],
            'new_product.reference' => ['sometimes', 'string', 'max:64'],
            'new_product.name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cartons' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pieces_per_carton' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'total_qty' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cbm' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,6})?$/', 'not_in:0'],
            'unit_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,8})?$/'],
        ]);
        $updated = $this->lists->reviewLine($list->id, $line->id, $validated, self::actor($request));

        return new JsonResponse(['data' => $this->line($updated, $this->catalog->describe(array_filter([$updated->matched_variant_id])))]);
    }

    public function validate(Request $request, string $list): JsonResponse
    {
        $id = PackingList::query()->findOrFail($list)->id;

        return new JsonResponse(['data' => $this->present($this->lists->validate($id, self::actor($request)))]);
    }

    public function reject(Request $request, string $list): JsonResponse
    {
        $id = PackingList::query()->findOrFail($list)->id;

        return new JsonResponse(['data' => $this->present($this->lists->reject($id, self::actor($request)))]);
    }

    /** @return array<string, mixed> */
    private function present(PackingList $list, bool $withLines = true): array
    {
        $data = [
            'id' => $list->id,
            'shipment_id' => $list->shipment_id,
            'supplier_id' => $list->supplier_id,
            'file_id' => $list->file_id,
            'file_format' => $list->file_format,
            'currency' => $list->currency,
            'status' => $list->status,
            'parse_report' => $list->parse_report,
            'validated_at' => $list->validated_at?->toIso8601ZuluString(),
            'created_at' => $list->created_at->toIso8601ZuluString(),
        ];

        if ($withLines) {
            $lines = $list->lines()->get();
            $variants = $this->catalog->describe(array_values(array_map('strval', $lines->pluck('matched_variant_id')->filter()->unique()->all())));
            $data['lines'] = array_values($lines->map(fn (PackingListLine $l) => $this->line($l, $variants))->all());
        }

        return $data;
    }

    /**
     * @param  array<string, VariantSummary>  $variants
     * @return array<string, mixed>
     */
    private function line(PackingListLine $l, array $variants): array
    {
        $prices = LogisticsPresenter::canSeePrices();

        return array_filter([
            'id' => $l->id,
            'row_number' => $l->row_number,
            'raw' => $l->raw,
            'supplier_reference' => $l->supplier_reference,
            'description' => $l->description,
            'cartons' => $l->cartons,
            'pieces_per_carton' => $l->pieces_per_carton,
            'total_qty' => $l->total_qty,
            'cbm' => $l->cbm,
            'unit_price' => $prices ? $l->unit_price : '__redacted__',
            'amount' => $prices ? $l->amount : '__redacted__',
            'match_status' => $l->match_status,
            'matched_variant' => $l->matched_variant_id !== null && isset($variants[$l->matched_variant_id]) ? $variants[$l->matched_variant_id]->toArray() : null,
            'errors' => $l->errors ?? [],
        ], fn ($v) => $v !== '__redacted__');
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
