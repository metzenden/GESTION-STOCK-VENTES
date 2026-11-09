<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\InvoiceDocuments;
use App\Modules\Billing\Application\InvoicingService;
use App\Modules\Billing\Contracts\DocumentLine;
use App\Modules\Billing\Http\Resources\BillingPresenter;
use App\Modules\Billing\Infrastructure\Models\CreditNote;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use App\Modules\Sales\Contracts\CustomerDirectory;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Avoirs clients (docs/05 §2.7) : brouillon, émission (numéro + imputation sur la facture d'origine), imputation. */
final class CreditNoteController
{
    public function __construct(
        private readonly InvoicingService $invoicing,
        private readonly BillingPresenter $presenter,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.status' => ['sometimes', 'in:draft,pending_approval,issued,rejected'],
            'filter.customer_id' => ['sometimes', 'string', 'size:26'],
            'filter.kind' => ['sometimes', 'in:cancellation,return,commercial,price_correction'],
        ]);
        $query = CreditNote::query()->orderByDesc('created_at')->orderByDesc('id');

        foreach ($validated['filter'] ?? [] as $field => $value) {
            $query->where($field, $value);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (CreditNote $n) => $this->presenter->creditNote($n, false));
    }

    public function show(string $note): JsonResponse
    {
        return $this->respond(CreditNote::query()->findOrFail($note));
    }

    /** Avoir commercial ou correction de prix, rattaché ou non à une facture. */
    public function store(Request $request, CustomerDirectory $customers): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string', 'size:26'],
            'origin_invoice_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'kind' => ['required', 'in:commercial,price_correction'],
            'reason' => ['required', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
        ]);
        $customer = $customers->find((string) $validated['customer_id']) ?? throw BusinessRuleViolation::invalidField('customer_id', 'Ce client n\'existe pas.');
        $invoice = isset($validated['origin_invoice_id']) ? Invoice::query()->find((string) $validated['origin_invoice_id']) : null;

        if (isset($validated['origin_invoice_id']) && ($invoice === null || $invoice->customer_id !== $customer['id'])) {
            throw BusinessRuleViolation::invalidField('origin_invoice_id', 'Cette facture n\'existe pas ou appartient à un autre client.');
        }

        $currency = $invoice->currency ?? (string) ($validated['currency'] ?? 'XOF');
        /** @var list<array{description: string, amount: string}> $lines */
        $lines = $validated['lines'];
        $note = $this->db->transaction(fn () => $this->invoicing->createDraft($customer['id'], $invoice?->id, $currency, $validated['kind'], $validated['reason'],
            array_map(fn (array $l) => new DocumentLine(null, $l['description'], '', 0, $l['amount'], '0', '0', '0', $l['amount']), $lines), null, self::actor($request)));

        return $this->respond($note, 201);
    }

    public function issue(Request $request, string $note): JsonResponse
    {
        $model = CreditNote::query()->findOrFail($note);

        return $this->respond($this->db->transaction(fn () => $this->invoicing->issueNote($model, self::actor($request))));
    }

    public function apply(Request $request, string $note): JsonResponse
    {
        $id = CreditNote::query()->findOrFail($note)->id;
        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
        ]);
        $invoice = Invoice::query()->find((string) $validated['invoice_id']) ?? throw BusinessRuleViolation::invalidField('invoice_id', 'Cette facture n\'existe pas.');

        return $this->respond($this->invoicing->apply($id, $invoice->id, $validated['amount'], self::actor($request)));
    }

    public function pdf(string $note, InvoiceDocuments $documents): Response
    {
        $model = CreditNote::query()->findOrFail($note);

        return InvoiceController::pdfResponse($documents->creditNotePdf($model), 'avoir-'.($model->number ?? 'brouillon').'.pdf');
    }

    /** Avoirs disponibles d'un client (CRN-003). */
    public function forCustomer(string $customer, CustomerDirectory $customers): JsonResponse
    {
        $found = $customers->find($customer) ?? abort(404);

        return new JsonResponse(['data' => array_values(CreditNote::query()->where('customer_id', $found['id'])->where('status', 'issued')
            ->where('amount_available', '>', 0)->orderBy('issued_at')->get()->map(fn (CreditNote $n) => $this->presenter->creditNote($n, false))->all())]);
    }

    private function respond(CreditNote $note, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->creditNote($note->refresh())], $status);
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
