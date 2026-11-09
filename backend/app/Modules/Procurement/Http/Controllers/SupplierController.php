<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Application\SupplierBillService;
use App\Modules\Procurement\Application\SupplierService;
use App\Modules\Procurement\Http\Resources\ProcurementPresenter;
use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Procurement\Infrastructure\Models\SupplierBill;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Fournisseurs (docs/05 §2.5). */
final class SupplierController
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierBillService $bills,
        private readonly ProcurementPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.q' => ['sometimes', 'string', 'max:100'],
            'filter.supplier_type' => ['sometimes', 'in:goods,forwarder,carrier,customs,other'],
            'filter.status' => ['sometimes', 'in:active,inactive'],
        ]);
        $filters = $validated['filter'] ?? [];
        $query = Supplier::query()->orderBy('name')->orderBy('id');

        if (isset($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        foreach (['supplier_type', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Supplier $s) => $this->presenter->supplier($s));
    }

    public function show(string $supplier): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->supplier(Supplier::query()->findOrFail($supplier))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(self::rules(true));

        return new JsonResponse(['data' => $this->presenter->supplier($this->suppliers->create($validated, self::actor($request)))], 201);
    }

    public function update(Request $request, string $supplier): JsonResponse
    {
        $id = Supplier::query()->findOrFail($supplier)->id;
        $validated = $request->validate(self::rules(false) + ['status' => ['sometimes', 'in:active,inactive']]);

        return new JsonResponse(['data' => $this->presenter->supplier($this->suppliers->update($id, $validated, self::actor($request)))]);
    }

    /** Relevé : engagements, règlements et solde par devise (PUR-002). */
    public function statement(string $supplier): JsonResponse
    {
        $supplier = Supplier::query()->findOrFail($supplier);
        $bills = SupplierBill::query()->with('supplier')->where('supplier_id', $supplier->id)->orderBy('bill_date')->orderBy('created_at')->orderBy('id')->get();

        return new JsonResponse(['data' => [
            'supplier' => $this->presenter->supplier($supplier),
            'balances' => $this->bills->balances($supplier->id),
            'bills' => $bills->map(fn (SupplierBill $b) => $this->presenter->bill($b))->values()->all(),
        ]]);
    }

    /** @return array<string, list<mixed>> */
    private static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => [$required, 'string', 'max:200'],
            'supplier_type' => ['sometimes', 'in:goods,forwarder,carrier,customs,other'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency' => [$required, 'string', 'size:3', Rule::exists('currencies', 'code')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
