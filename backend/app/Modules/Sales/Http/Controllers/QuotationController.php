<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\QuotationService;
use App\Modules\Sales\Http\Resources\SalesPresenter;
use App\Modules\Sales\Infrastructure\Models\Quotation;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Devis (docs/05 §2.6). */
final class QuotationController
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly SalesPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:draft,sent,accepted,converted,expired,cancelled'],
            'filter.customer_id' => ['sometimes', 'string', 'size:26'],
        ]);
        $query = Quotation::query()->with('customer')->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Quotation $q) => $this->presenter->quotation($q, false));
    }

    public function show(string $quotation): JsonResponse
    {
        return $this->respond(Quotation::query()->findOrFail($quotation));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string', 'size:26'],
            'price_list_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ] + SalesRequest::discountRules() + SalesRequest::lineRules(true));

        return $this->respond($this->quotations->create($validated, SalesRequest::lines($validated), SalesRequest::actor($request)), 201);
    }

    public function update(Request $request, string $quotation): JsonResponse
    {
        $id = Quotation::query()->findOrFail($quotation)->id;
        $validated = $request->validate([
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ] + SalesRequest::discountRules() + SalesRequest::lineRules(false));

        return $this->respond($this->quotations->update($id, array_diff_key($validated, ['lines' => true]),
            isset($validated['lines']) ? SalesRequest::lines($validated) : null, SalesRequest::actor($request)));
    }

    public function send(Request $request, string $quotation): JsonResponse
    {
        return $this->respond($this->quotations->send(Quotation::query()->findOrFail($quotation)->id, SalesRequest::actor($request)));
    }

    public function accept(Request $request, string $quotation): JsonResponse
    {
        return $this->respond($this->quotations->accept(Quotation::query()->findOrFail($quotation)->id, SalesRequest::actor($request)));
    }

    public function cancel(Request $request, string $quotation): JsonResponse
    {
        return $this->respond($this->quotations->cancel(Quotation::query()->findOrFail($quotation)->id, SalesRequest::actor($request)));
    }

    public function convert(Request $request, string $quotation): JsonResponse
    {
        $id = Quotation::query()->findOrFail($quotation)->id;
        $validated = $request->validate(['warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26']]);
        $result = $this->quotations->convert($id, $validated['warehouse_id'] ?? null, SalesRequest::actor($request));

        return new JsonResponse(['data' => ['order_id' => $result['order_id'], 'quotation' => $this->presenter->quotation(Quotation::query()->findOrFail($id))],
            'meta' => ['warnings' => $result['warnings']]], 201);
    }

    private function respond(Quotation $quotation, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->quotation($quotation->load('customer'))], $status);
    }
}
