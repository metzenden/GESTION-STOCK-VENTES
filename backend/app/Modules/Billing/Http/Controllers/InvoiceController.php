<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\InvoiceDocuments;
use App\Modules\Billing\Http\Resources\BillingPresenter;
use App\Modules\Billing\Infrastructure\Models\CreditNote;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use App\Modules\Sales\Contracts\CustomerDirectory;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Factures, créances et relevés clients (docs/05 §2.7). */
final class InvoiceController
{
    public function __construct(
        private readonly BillingPresenter $presenter,
        private readonly InvoiceDocuments $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.payment_status' => ['sometimes', 'in:unpaid,partially_paid,paid'],
            'filter.status' => ['sometimes', 'in:issued,cancelled'],
            'filter.customer_id' => ['sometimes', 'string', 'size:26'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['sometimes', 'string', 'max:64'],
        ]);
        $query = $this->filtered(Invoice::query(), $validated)->orderByDesc('issue_date')->orderByDesc('number');

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Invoice $i) => $this->presenter->invoice($i, false));
    }

    public function show(string $invoice): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->invoice(Invoice::query()->findOrFail($invoice))]);
    }

    public function pdf(string $invoice): Response
    {
        $model = Invoice::query()->findOrFail($invoice);

        return self::pdfResponse($this->documents->invoicePdf($model), "facture-{$model->number}.pdf");
    }

    public function shareLink(string $invoice): JsonResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        return new JsonResponse(['data' => ['url' => $this->documents->shareLink($model), 'expires_in_days' => InvoiceDocuments::LINK_DAYS]]);
    }

    public function whatsapp(string $invoice): JsonResponse
    {
        return new JsonResponse(['data' => $this->documents->whatsapp(Invoice::query()->findOrFail($invoice), false)]);
    }

    /** Créances : factures émises avec un solde dû (filtre « en retard »). */
    public function receivables(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['filter.overdue' => ['sometimes', 'boolean'], 'filter.customer_id' => ['sometimes', 'string', 'size:26']]);
        $query = $this->filtered(Invoice::query(), $validated)->where('status', 'issued')->where('balance_due', '>', 0)->orderBy('due_date')->orderBy('number');

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Invoice $i) => $this->presenter->invoice($i, false));
    }

    public function reminder(string $invoice): JsonResponse
    {
        return new JsonResponse(['data' => $this->documents->whatsapp(Invoice::query()->findOrFail($invoice), true)]);
    }

    /** Relevé client : factures, règlements et avoirs, solde par devise. */
    public function statement(string $customer, CustomerDirectory $customers): JsonResponse
    {
        $found = $customers->find($customer) ?? abort(404);
        $invoices = Invoice::query()->where('customer_id', $found['id'])->orderBy('issue_date')->orderBy('number')->get();
        $credits = CreditNote::query()->where('customer_id', $found['id'])->where('status', 'issued')->get();
        $balances = $invoices->where('status', 'issued')->groupBy('currency')->map(fn ($group, $currency) => [
            'currency' => (string) $currency,
            'invoiced' => self::sum($group->pluck('total')->all()),
            'paid' => self::sum($group->pluck('amount_paid')->all()),
            'credited' => self::sum($group->pluck('amount_credited')->all()),
            'balance_due' => self::sum($group->pluck('balance_due')->all()),
        ])->values()->all();

        return new JsonResponse(['data' => [
            'customer' => $found,
            'balances' => $balances,
            'available_credit' => $credits->groupBy('currency')->map(fn ($g, $cur) => ['currency' => (string) $cur, 'amount' => self::sum($g->pluck('amount_available')->all())])->values()->all(),
            'invoices' => array_values($invoices->map(fn (Invoice $i) => $this->presenter->invoice($i, false))->all()),
        ]]);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @param  array<string, mixed>  $validated
     * @return Builder<Invoice>
     */
    private function filtered(Builder $query, array $validated): Builder
    {
        $filters = (array) ($validated['filter'] ?? []);

        foreach (['payment_status', 'status', 'customer_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if ((bool) ($filters['overdue'] ?? false)) {
            $query->where('status', 'issued')->where('payment_status', '<>', 'paid')->where('due_date', '<', CarbonImmutable::now()->toDateString());
        }

        if (isset($filters['from'])) {
            $query->where('issue_date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('issue_date', '<=', $filters['to']);
        }

        if (isset($validated['q'])) {
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('customer_snapshot->name', 'like', $term));
        }

        return $query;
    }

    /** @param array<mixed> $values */
    private static function sum(array $values): string
    {
        $total = BigDecimal::zero();

        foreach ($values as $v) {
            $total = $total->plus((string) $v);
        }

        return (string) $total->toScale(4);
    }

    public static function pdfResponse(string $pdf, string $name): Response
    {
        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }
}
