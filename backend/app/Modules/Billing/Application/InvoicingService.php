<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Billing\Contracts\DocumentLine;
use App\Modules\Billing\Contracts\InvoiceDraft;
use App\Modules\Billing\Contracts\InvoiceSummary;
use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Billing\Infrastructure\Models\CreditApplication;
use App\Modules\Billing\Infrastructure\Models\CreditNote;
use App\Modules\Billing\Infrastructure\Models\CreditNoteLine;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use App\Modules\Billing\Infrastructure\Models\InvoiceLine;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Factures (BIL-001 à BIL-007) et avoirs (CRN-001 à CRN-005).
 * Une facture émise est immuable : seuls évoluent les montants réglés et imputés et les statuts (déclencheur en base).
 */
final class InvoicingService implements Invoicing
{
    public const METHOD_LABELS = ['cash' => 'Espèces', 'wave' => 'Wave', 'orange_money' => 'Orange Money', 'bank_transfer' => 'Virement bancaire'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly CompanyPreferences $preferences,
        private readonly ApprovalGate $approvals,
        private readonly AuditRecorder $audit,
    ) {}

    public function issue(InvoiceDraft $draft, ?string $actorId): string
    {
        $issueDate = CarbonImmutable::instance($draft->issueDate)->startOfDay();
        $invoice = new Invoice;
        $invoice->fill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Invoice, $issueDate),
            'sales_order_id' => $draft->salesOrderId,
            'customer_id' => $draft->customerId,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->addDays($draft->paymentTermsDays)->toDateString(),
            'currency' => $draft->currency,
            'subtotal' => $draft->subtotal,
            'discount_total' => $draft->discountTotal,
            'tax_total' => $draft->taxTotal,
            'total' => $draft->total,
            'payment_status' => Decimal::of($draft->total)->isZero() ? 'paid' : 'unpaid',
            'status' => 'issued',
            'customer_snapshot' => $draft->customerSnapshot,
            'company_snapshot' => $this->companySnapshot(),
        ])->forceFill(['created_by' => $actorId])->save();

        foreach ($draft->lines as $index => $line) {
            (new InvoiceLine)->fill([
                'invoice_id' => $invoice->id,
                'sales_order_line_id' => $line->salesOrderLineId,
                'position' => $index + 1,
                'description' => $line->description,
                'unit_label' => $line->unitLabel,
                'qty_units' => $line->qtyUnits,
                'unit_price' => $line->unitPrice,
                'discount_amount' => $line->discountAmount,
                'tax_rate' => $line->taxRate,
                'tax_amount' => $line->taxAmount,
                'line_total' => $line->lineTotal,
            ])->save();
        }

        $this->audit->record('invoice.issued', 'invoice', $invoice->id, [], ['number' => $invoice->number, 'total' => $draft->total, 'currency' => $draft->currency]);

        return $invoice->id;
    }

    public function find(string $invoiceId): ?InvoiceSummary
    {
        $invoice = Invoice::query()->find($invoiceId);

        return $invoice === null ? null : self::summary($invoice);
    }

    public function lock(string $invoiceId): InvoiceSummary
    {
        return self::summary(Invoice::query()->lockForUpdate()->findOrFail($invoiceId));
    }

    public function recordPayment(string $invoiceId, string $method, string $signedAmount, bool $clearMethod = false): InvoiceSummary
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        $amount = Decimal::of($signedAmount);

        if ($amount->isPositive()) {
            if ($invoice->status !== 'issued') {
                throw BusinessRuleViolation::invalidTransition('la facture', $invoice->status, 'encaisser');
            }

            // PAY-001 : le premier règlement fixe le moyen de paiement.
            if ($invoice->payment_method !== null && $invoice->payment_method !== $method) {
                $label = self::METHOD_LABELS[$invoice->payment_method] ?? $invoice->payment_method;
                throw new BusinessRuleViolation('payment_method_locked', 'Moyen de paiement verrouillé',
                    "Cette facture est réglée par {$label}. Utilisez {$label} ou la procédure de changement de moyen de paiement.", 422, ['payment_method' => $invoice->payment_method]);
            }

            // PAY-003 : jamais de trop-perçu.
            if ($amount->isGreaterThan(Decimal::of($invoice->balance_due))) {
                throw new BusinessRuleViolation('overpayment', 'Montant supérieur au solde',
                    "Le règlement dépasse le solde dû ({$invoice->balance_due} {$invoice->currency}).", 422, ['balance_due' => $invoice->balance_due]);
            }
        }

        $paid = Decimal::of($invoice->amount_paid)->plus($amount);
        $invoice->forceFill([
            'amount_paid' => (string) $paid,
            'payment_method' => $clearMethod ? null : ($invoice->payment_method ?? $method),
        ]);
        $invoice->forceFill(['payment_status' => self::paymentStatus($invoice)])->save();

        return self::summary($invoice->refresh());
    }

    public function creditInvoice(string $invoiceId, string $kind, string $reason, array $lines, ?string $returnId, ?string $actorId): array
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        $note = $this->createDraft($invoice->customer_id, $invoice->id, $invoice->currency, $kind, $reason, $lines, $returnId, $actorId);
        $note = $this->issueNote($note, $actorId);

        return ['id' => $note->id, 'number' => $note->number, 'status' => $note->status, 'total' => $note->total];
    }

    /** @param list<DocumentLine> $lines */
    public function createDraft(string $customerId, ?string $originInvoiceId, string $currency, string $kind, string $reason, array $lines, ?string $returnId, ?string $actorId): CreditNote
    {
        if ($lines === []) {
            throw BusinessRuleViolation::invalidField('lines', 'L\'avoir ne contient aucune ligne.');
        }

        $total = BigDecimal::zero();

        foreach ($lines as $index => $line) {
            if (! Decimal::of($line->lineTotal)->isPositive()) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/line_total", 'Le montant de la ligne doit être positif.');
            }

            $total = $total->plus(Decimal::of($line->lineTotal));
        }

        $note = new CreditNote;
        $note->fill([
            'customer_id' => $customerId,
            'origin_invoice_id' => $originInvoiceId,
            'return_id' => $returnId,
            'kind' => $kind,
            'reason' => $reason,
            'currency' => $currency,
            'total' => (string) $total,
            'status' => 'draft',
        ])->forceFill(['created_by' => $actorId])->save();

        foreach ($lines as $line) {
            (new CreditNoteLine)->fill([
                'credit_note_id' => $note->id,
                'sales_order_line_id' => $line->salesOrderLineId,
                'description' => $line->description,
                'quantity' => $line->qtyBase,
                'unit_price' => $line->unitPrice,
                'tax_amount' => $line->taxAmount,
                'line_total' => $line->lineTotal,
            ])->save();
        }

        return $note;
    }

    /** CRN-001, CRN-002, CRN-004 : numéro à l'émission, imputation automatique sur la facture d'origine. */
    public function issueNote(CreditNote $note, ?string $actorId, bool $approved = false): CreditNote
    {
        $note = CreditNote::query()->lockForUpdate()->findOrFail($note->id);

        if ($note->status !== ($approved ? 'pending_approval' : 'draft')) {
            throw BusinessRuleViolation::invalidTransition('l\'avoir', $note->status, 'émettre');
        }

        if (! $approved && $this->approvals->requiresApproval('credit_note', ['kind' => $note->kind, 'amount' => $note->total, 'currency' => $note->currency])) {
            $note->forceFill(['status' => 'pending_approval', 'version' => $note->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('credit_note.submitted', 'credit_note', $note->id, [], ['total' => $note->total, 'approval' => true]);
            $this->approvals->submit('credit_note', 'credit_note', $note->id, [], "Avoir {$note->kind} de {$note->total} {$note->currency} : {$note->reason}");

            return $note;
        }

        $now = CarbonImmutable::now();
        $note->forceFill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::CreditNote, $this->today()),
            'status' => 'issued',
            'issued_at' => $now,
            'company_snapshot' => $this->companySnapshot(),
            'version' => $note->version + 1,
            'updated_by' => $actorId,
        ])->save();
        $this->audit->record('credit_note.issued', 'credit_note', $note->id, ['status' => 'draft'], ['number' => $note->number, 'total' => $note->total, 'kind' => $note->kind]);

        if ($note->origin_invoice_id !== null) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($note->origin_invoice_id);
            $amount = BigDecimal::min(Decimal::of($note->total), Decimal::of($invoice->balance_due));

            if ($amount->isPositive()) {
                $this->applyLocked($note, $invoice, $amount, $actorId);
            }

            $this->closeIfCancelled($invoice);
        }

        return $note->refresh();
    }

    public function rejectNote(string $noteId, ?string $actorId): void
    {
        $note = CreditNote::query()->lockForUpdate()->findOrFail($noteId);

        if ($note->status === 'pending_approval') {
            $note->forceFill(['status' => 'rejected', 'version' => $note->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('credit_note.rejected', 'credit_note', $note->id, ['status' => 'pending_approval'], ['status' => 'rejected']);
        }
    }

    /** CRN-003 : imputation ≤ min(disponible, solde dû), même client, même devise. */
    public function apply(string $noteId, string $invoiceId, string $amount, ?string $actorId): CreditNote
    {
        return $this->db->transaction(function () use ($noteId, $invoiceId, $amount, $actorId) {
            $note = CreditNote::query()->lockForUpdate()->findOrFail($noteId);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $value = Decimal::of($amount);

            if ($note->status !== 'issued') {
                throw new BusinessRuleViolation('credit_not_available', 'Avoir non disponible', 'Seul un avoir émis peut être imputé (CRN-004).');
            }

            if ($invoice->customer_id !== $note->customer_id || $invoice->currency !== $note->currency) {
                throw BusinessRuleViolation::invalidField('invoice_id', 'La facture doit appartenir au même client, dans la même devise.');
            }

            if ($invoice->status !== 'issued') {
                throw BusinessRuleViolation::invalidTransition('la facture', $invoice->status, 'imputer un avoir');
            }

            $max = BigDecimal::min(Decimal::of($note->amount_available), Decimal::of($invoice->balance_due));

            if (! $value->isPositive() || $value->isGreaterThan($max)) {
                throw BusinessRuleViolation::invalidField('amount', "Le montant imputé doit être compris entre 0 et {$max}.");
            }

            $this->applyLocked($note, $invoice, $value, $actorId);

            return $note->refresh();
        });
    }

    private function applyLocked(CreditNote $note, Invoice $invoice, BigDecimal $amount, ?string $actorId): void
    {
        (new CreditApplication)->forceFill([
            'credit_note_id' => $note->id,
            'invoice_id' => $invoice->id,
            'amount' => (string) $amount,
            'applied_at' => CarbonImmutable::now(),
            'created_by' => $actorId,
        ])->save();
        $note->forceFill(['amount_applied' => (string) Decimal::of($note->amount_applied)->plus($amount)])->save();
        $invoice->forceFill(['amount_credited' => (string) Decimal::of($invoice->amount_credited)->plus($amount)]);
        $invoice->forceFill(['payment_status' => self::paymentStatus($invoice)])->save();
        $this->audit->record('credit_note.applied', 'invoice', $invoice->id, [], ['credit_note_id' => $note->id, 'amount' => (string) $amount]);
    }

    /** BIL-003 : facture annulée quand les avoirs d'annulation couvrent son total. */
    private function closeIfCancelled(Invoice $invoice): void
    {
        $cancelled = CreditNote::query()->where('origin_invoice_id', $invoice->id)->where('kind', 'cancellation')->where('status', 'issued')->sum('total');

        if (Decimal::of((string) $cancelled)->isGreaterThanOrEqualTo(Decimal::of($invoice->total)) && $invoice->status === 'issued') {
            $invoice->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('invoice.cancelled', 'invoice', $invoice->id, ['status' => 'issued'], ['status' => 'cancelled']);
        }
    }

    private static function paymentStatus(Invoice $invoice): string
    {
        $settled = Decimal::of($invoice->amount_paid)->plus(Decimal::of($invoice->amount_credited));
        $total = Decimal::of($invoice->total);

        return match (true) {
            $settled->isGreaterThanOrEqualTo($total) => 'paid',
            $settled->isPositive() => 'partially_paid',
            default => 'unpaid',
        };
    }

    /** @return array<string, mixed> identité et mentions saisies par l'entreprise, figées (BIL-006). */
    private function companySnapshot(): array
    {
        return $this->companies->identity($this->tenant->requireCompanyId()) + ['documents' => $this->preferences->documentTexts()];
    }

    public static function summary(Invoice $i): InvoiceSummary
    {
        return new InvoiceSummary($i->id, $i->number, $i->sales_order_id, $i->customer_id, $i->currency, $i->total, $i->amount_paid,
            $i->amount_credited, $i->balance_due, $i->payment_method, $i->payment_status, $i->status);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
