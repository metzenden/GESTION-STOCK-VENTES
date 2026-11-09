<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Infrastructure\Models\CustomerPayment;
use App\Modules\Shared\Contracts\PdfRenderer;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use App\Modules\Shared\Infrastructure\Money\MoneyFormatter;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Règlements clients et reçus (docs/05 §2.7). */
final class PaymentController
{
    public const METHOD_LABELS = ['cash' => 'Espèces', 'wave' => 'Wave', 'orange_money' => 'Orange Money', 'bank_transfer' => 'Virement bancaire'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly Invoicing $invoices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.method' => ['sometimes', 'in:cash,wave,orange_money,bank_transfer'],
            'filter.customer_id' => ['sometimes', 'string', 'size:26'],
            'filter.invoice_id' => ['sometimes', 'string', 'size:26'],
        ]);
        $query = CustomerPayment::query()->orderByDesc('paid_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (CustomerPayment $p) => $this->present($p));
    }

    public function forInvoice(string $invoice): JsonResponse
    {
        $found = $this->invoices->find($invoice) ?? abort(404);

        return new JsonResponse(['data' => array_values(CustomerPayment::query()->where('invoice_id', $found->id)->orderBy('paid_at')->orderBy('id')->get()
            ->map(fn (CustomerPayment $p) => $this->present($p))->all())]);
    }

    public function show(string $payment): JsonResponse
    {
        return new JsonResponse(['data' => $this->present(CustomerPayment::query()->findOrFail($payment))]);
    }

    /** PAY-001 à PAY-005. */
    public function store(Request $request, string $invoice): JsonResponse
    {
        $found = $this->invoices->find($invoice) ?? abort(404);
        $validated = $request->validate([
            'method' => ['required', 'in:cash,wave,orange_money,bank_transfer'],
            'financial_account_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'paid_at' => ['sometimes', 'date'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $payment = $this->payments->create($found->id, $validated['method'], $validated['financial_account_id'], $validated['amount'],
            isset($validated['paid_at']) ? CarbonImmutable::parse($validated['paid_at']) : CarbonImmutable::now(), $validated['external_reference'] ?? null, self::actor($request));

        return new JsonResponse(['data' => $this->present($payment)], 201);
    }

    public function reverse(Request $request, string $payment): JsonResponse
    {
        $id = CustomerPayment::query()->findOrFail($payment)->id;
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new JsonResponse(['data' => $this->present($this->payments->reverse($id, $validated['reason'], self::actor($request)))], 201);
    }

    /** PAY-007. */
    public function changeMethod(Request $request, string $invoice): JsonResponse
    {
        $found = $this->invoices->find($invoice) ?? abort(404);
        $validated = $request->validate([
            'method' => ['required', 'in:cash,wave,orange_money,bank_transfer'],
            'financial_account_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $payment = $this->payments->changeMethod($found->id, $validated['method'], $validated['financial_account_id'], $validated['reason'], self::actor($request));

        // Soumis à approbation : rien n'est encore modifié (202, demande dans meta.approval_request_id).
        return $payment === null ? new JsonResponse(['data' => null], 202) : new JsonResponse(['data' => $this->present($payment)], 201);
    }

    public function receipt(string $payment, ViewFactory $views, PdfRenderer $pdf, MoneyFormatter $money, TenantContext $tenant, CompanyDirectory $companies): Response
    {
        $model = CustomerPayment::query()->findOrFail($payment);
        $invoice = $this->invoices->find($model->invoice_id) ?? abort(404);
        $m = fn (string $a) => $money->format($a, $invoice->currency);
        $html = $views->make('pdf.document', [
            'title' => str_starts_with($model->amount, '-') ? 'Contre-passation de reçu' : 'Reçu de paiement',
            'number' => $model->number,
            'company' => $companies->identity($tenant->requireCompanyId()),
            'meta' => ['Date' => $model->paid_at->setTimezone($companies->find($tenant->requireCompanyId())->timezone ?? 'Africa/Dakar')->format('d/m/Y H:i'), 'Facture' => $invoice->number],
            'party' => null,
            'columns' => ['Moyen de paiement', 'Référence', 'Montant'],
            'rows' => [[self::METHOD_LABELS[$model->method] ?? $model->method, (string) ($model->external_reference ?? ''), $m($model->amount)]],
            'totals' => ['Reste à payer sur la facture' => $m($invoice->balanceDue)],
            'notice' => $model->reversal_reason,
            'texts' => [],
        ])->render();

        return new Response($pdf->renderHtml($html), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="recu-'.$model->number.'.pdf"']);
    }

    /** @return array<string, mixed> */
    private function present(CustomerPayment $p): array
    {
        $invoice = $this->invoices->find($p->invoice_id);

        return [
            'id' => $p->id,
            'number' => $p->number,
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $invoice?->number,
            'customer_id' => $p->customer_id,
            'financial_account_id' => $p->financial_account_id,
            'method' => $p->method,
            'amount' => $p->amount,
            'currency' => $invoice?->currency,
            'paid_at' => $p->paid_at->toIso8601ZuluString(),
            'external_reference' => $p->external_reference,
            'status' => $p->status,
            'reverses_payment_id' => $p->reverses_payment_id,
            'reversal_reason' => $p->reversal_reason,
            'invoice_balance_due' => $invoice?->balanceDue,
            'invoice_payment_status' => $invoice?->paymentStatus,
        ];
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
