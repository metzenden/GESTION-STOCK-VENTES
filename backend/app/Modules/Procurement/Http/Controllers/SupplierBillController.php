<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\SupplierBillService;
use App\Modules\Procurement\Http\Resources\ProcurementPresenter;
use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Procurement\Infrastructure\Models\SupplierBill;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Engagements fournisseurs (D-026) : consultation et saisie manuelle (engagement ou avoir). */
final class SupplierBillController
{
    public function __construct(
        private readonly SupplierBillService $bills,
        private readonly ProcurementPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.supplier_id' => ['sometimes', 'string', 'size:26'],
            'filter.status' => ['sometimes', 'in:open,partially_paid,paid,cancelled'],
            'filter.currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $query = SupplierBill::query()->with('supplier')->orderByDesc('bill_date')->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (SupplierBill $b) => $this->presenter->bill($b));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'supplier_invoice_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $supplier = Supplier::query()->findOrFail((string) $validated['supplier_id']);
        $actor = $request->user()?->getAuthIdentifier();

        $bill = $this->bills->createManual($supplier->id, $validated['amount'], CarbonImmutable::parse($validated['bill_date']),
            isset($validated['due_date']) ? CarbonImmutable::parse($validated['due_date']) : null,
            $validated['supplier_invoice_ref'] ?? null, $validated['description'] ?? null, is_string($actor) ? $actor : null);

        return new JsonResponse(['data' => $this->presenter->bill($bill->load('supplier'))], 201);
    }
}
