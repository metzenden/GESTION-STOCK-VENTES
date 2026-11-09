<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\CustomerService;
use App\Modules\Sales\Http\Resources\SalesPresenter;
use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Clients (docs/05 §2.6) : recherche par nom, code ou téléphone. */
final class CustomerController
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly SalesPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'filter.status' => ['sometimes', 'in:active,inactive']]);
        $query = Customer::query()->orderByDesc('is_walk_in')->orderBy('name')->orderBy('id');

        if (isset($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $phone = CustomerService::normalizePhone((string) $validated['q']);
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('phone', 'like', $term)
                ->when($phone !== null && $phone !== '', fn ($q2) => $q2->orWhere('phone', $phone)));
        }

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Customer $c) => $this->presenter->customer($c));
    }

    public function show(string $customer): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->customer(Customer::query()->findOrFail($customer))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(self::rules(true));

        return new JsonResponse(['data' => $this->presenter->customer($this->customers->create($validated, SalesRequest::actor($request)))], 201);
    }

    public function update(Request $request, string $customer): JsonResponse
    {
        $id = Customer::query()->findOrFail($customer)->id;
        $validated = $request->validate(self::rules(false) + ['status' => ['sometimes', 'in:active,inactive']]);

        return new JsonResponse(['data' => $this->presenter->customer($this->customers->update($id, $validated, SalesRequest::actor($request)))]);
    }

    /** @return array<string, list<string>> */
    private static function rules(bool $creating): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'customer_type' => ['sometimes', 'in:individual,business'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_price_list_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
