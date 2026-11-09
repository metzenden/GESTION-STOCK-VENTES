<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\CounterSaleService;
use App\Modules\Sales\Application\SalesOrderService;
use App\Modules\Sales\Http\Resources\SalesPresenter;
use App\Modules\Sales\Infrastructure\Models\Dispute;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Commandes clients, litiges et vente comptoir (docs/05 §2.6). */
final class SalesOrderController
{
    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly SalesPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:draft,pending_approval,confirmed,preparing,ready,in_delivery,partially_delivered,delivered,cancelled'],
            'filter.customer_id' => ['sometimes', 'string', 'size:26'],
            'filter.channel' => ['sometimes', 'in:standard,counter'],
            'filter.has_dispute' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:64'],
        ]);
        $query = SalesOrder::query()->with('customer')->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $field === 'has_dispute' ? (bool) $value : $value);
        }

        if (isset($validated['q'])) {
            $query->where('number', 'like', '%'.addcslashes((string) $validated['q'], '%_\\').'%');
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (SalesOrder $o) => $this->presenter->order($o, false));
    }

    public function show(string $order): JsonResponse
    {
        return $this->respond(SalesOrder::query()->findOrFail($order));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(self::headerRules(true) + SalesRequest::discountRules() + SalesRequest::lineRules(true));
        $order = $this->orders->create(array_diff_key($validated, ['lines' => true]), SalesRequest::lines($validated), SalesRequest::actor($request));

        return $this->respond($order, 201);
    }

    public function update(Request $request, string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(self::headerRules(false) + SalesRequest::discountRules() + SalesRequest::lineRules(false) + ['version' => ['sometimes', 'integer', 'min:1']]);
        $updated = $this->orders->update($id, array_diff_key($validated, ['lines' => true, 'version' => true]),
            isset($validated['lines']) ? SalesRequest::lines($validated) : null, isset($validated['version']) ? (int) $validated['version'] : null, SalesRequest::actor($request));

        return $this->respond($updated);
    }

    public function submit(Request $request, string $order): JsonResponse
    {
        $result = $this->orders->submit(SalesOrder::query()->findOrFail($order)->id, SalesRequest::actor($request));

        return $this->respond($result['order'], 200, $result['warnings']);
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->respond($this->orders->cancel($id, $validated['reason'], SalesRequest::actor($request)));
    }

    public function cancelRemaining(Request $request, string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->respond($this->orders->cancelRemaining($id, $validated['reason'], SalesRequest::actor($request)));
    }

    public function openDispute(Request $request, string $order): JsonResponse
    {
        $id = SalesOrder::query()->findOrFail($order)->id;
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new JsonResponse(['data' => $this->presenter->dispute($this->orders->openDispute($id, $validated['reason'], SalesRequest::actor($request)))], 201);
    }

    public function resolveDispute(Request $request, string $dispute): JsonResponse
    {
        $id = Dispute::query()->findOrFail($dispute)->id;
        $validated = $request->validate(['resolution' => ['required', 'string', 'max:500']]);

        return new JsonResponse(['data' => $this->presenter->dispute($this->orders->resolveDispute($id, $validated['resolution'], SalesRequest::actor($request)))]);
    }

    /** SAL-008 : vente comptoir en une transaction. */
    public function counterSale(Request $request, CounterSaleService $counter): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.method' => ['required_with:payment', 'in:cash,wave,orange_money,bank_transfer'],
            'payment.financial_account_id' => ['required_with:payment', 'string', 'size:26'],
            'payment.amount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'payment.external_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ] + SalesRequest::discountRules() + SalesRequest::lineRules(true));

        if (isset($validated['payment']) && ! Gate::allows('customer_payments.create')) {
            abort(403);
        }

        /** @var array{method: string, financial_account_id: string, amount?: string|null, external_reference?: string|null}|null $payment */
        $payment = $validated['payment'] ?? null;
        /** @var array{type: string, value: string}|null $discount */
        $discount = $validated['global_discount'] ?? null;
        $result = $counter->sell($validated['customer_id'] ?? null, $validated['warehouse_id'] ?? null, SalesRequest::lines($validated), $discount, $payment, SalesRequest::actor($request));

        return new JsonResponse(['data' => $this->presenter->order($result['order']->load('customer')), 'meta' => ['warnings' => $result['warnings'], 'payment' => $result['payment']]], 201);
    }

    /** @return array<string, list<string>> */
    private static function headerRules(bool $creating): array
    {
        return [
            'customer_id' => [$creating ? 'required' : 'sometimes', 'string', 'size:26'],
            'price_list_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'fulfilment_mode' => ['sometimes', 'in:pickup,delivery'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'recipient_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @param list<array<string, mixed>> $warnings */
    private function respond(SalesOrder $order, int $status = 200, array $warnings = []): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->order($order->load('customer')), 'meta' => ['warnings' => $warnings]], $status);
    }
}
