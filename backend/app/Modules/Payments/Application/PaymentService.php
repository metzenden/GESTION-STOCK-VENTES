<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\Payments\Contracts\PaymentRecorder;
use App\Modules\Payments\Infrastructure\Models\CustomerPayment;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Règlements clients (L4-10, L4-11) : un règlement écrit, dans la même transaction, le mouvement financier,
 * la mise à jour de la facture et un reçu numéroté (PAY-005). Une erreur se corrige par contre-passation (PAY-006).
 */
final class PaymentService implements PaymentRecorder
{
    /** PAY-004 : type de compte attendu pour chaque moyen de paiement. */
    public const ACCOUNT_TYPES = ['cash' => 'cash', 'wave' => 'wave', 'orange_money' => 'orange_money', 'bank_transfer' => 'bank'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly Invoicing $invoices,
        private readonly FinancialLedger $ledger,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly ApprovalGate $approvals,
        private readonly AuditRecorder $audit,
    ) {}

    public function record(string $invoiceId, string $method, string $accountId, string $amount, DateTimeInterface $paidAt, ?string $externalReference, ?string $actorId): array
    {
        $payment = $this->db->transaction(fn () => $this->post($invoiceId, $method, $accountId, $amount, CarbonImmutable::instance($paidAt), $externalReference, $actorId), 3);

        return ['id' => $payment->id, 'number' => $payment->number];
    }

    public function create(string $invoiceId, string $method, string $accountId, string $amount, CarbonImmutable $paidAt, ?string $externalReference, ?string $actorId): CustomerPayment
    {
        return $this->db->transaction(fn () => $this->post($invoiceId, $method, $accountId, $amount, $paidAt, $externalReference, $actorId), 3);
    }

    /** PAY-006 : règlement négatif lié, mouvement financier inverse, solde rouvert. */
    public function reverse(string $paymentId, string $reason, ?string $actorId, bool $approved = false): CustomerPayment
    {
        return $this->db->transaction(function () use ($paymentId, $reason, $actorId, $approved) {
            $payment = CustomerPayment::query()->findOrFail($paymentId);
            $this->invoices->lock($payment->invoice_id);
            $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'posted' || Decimal::of($payment->amount)->isNegative()) {
                throw BusinessRuleViolation::alreadyProcessed('Ce règlement a déjà été contre-passé.');
            }

            if (! $approved && $this->approvals->requiresApproval('payment_reversal', ['amount' => $payment->amount])) {
                $this->approvals->submit('payment_reversal', 'customer_payment', $payment->id, ['reason' => $reason], "Contre-passation du règlement {$payment->number} : {$reason}");

                return $payment;
            }

            return $this->reverseLocked($payment, $reason, $actorId);
        }, 3);
    }

    /** PAY-007 : contre-passation de tous les règlements, puis un règlement unique avec le nouveau moyen. */
    public function changeMethod(string $invoiceId, string $method, string $accountId, string $reason, ?string $actorId, bool $approved = false): ?CustomerPayment
    {
        return $this->db->transaction(function () use ($invoiceId, $method, $accountId, $reason, $actorId, $approved) {
            $invoice = $this->invoices->lock($invoiceId);

            if ($invoice->paymentMethod === null) {
                throw new BusinessRuleViolation('no_payment', 'Aucun règlement', 'Cette facture n\'a reçu aucun règlement.');
            }

            if ($invoice->paymentMethod === $method) {
                throw BusinessRuleViolation::invalidField('method', 'La facture est déjà réglée par ce moyen.');
            }

            $active = CustomerPayment::query()->where('invoice_id', $invoiceId)->where('status', 'posted')->where('amount', '>', 0)->lockForUpdate()->orderBy('id')->get();
            if (! $approved && $this->approvals->requiresApproval('payment_method_change', ['amount' => (string) $active->sum('amount')])) {
                $this->approvals->submit('payment_method_change', 'invoice', $invoiceId, ['method' => $method, 'account_id' => $accountId, 'reason' => $reason],
                    "Changement de moyen de paiement ({$invoice->paymentMethod} → {$method}) : {$reason}");

                return null;
            }

            $total = BigDecimal::zero();

            foreach ($active as $payment) {
                $total = $total->plus(Decimal::of($payment->amount));
                $this->reverseLocked($payment, "Changement de moyen de paiement : {$reason}", $actorId);
            }

            return $this->post($invoiceId, $method, $accountId, (string) $total, CarbonImmutable::now(), null, $actorId);
        }, 3);
    }

    private function post(string $invoiceId, string $method, string $accountId, string $amount, CarbonImmutable $paidAt, ?string $externalReference, ?string $actorId): CustomerPayment
    {
        $invoice = $this->invoices->lock($invoiceId);
        $value = Decimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (! $value->isPositive()) {
            throw BusinessRuleViolation::invalidField('amount', 'Le montant doit être positif.');
        }

        $account = $this->ledger->account($accountId);

        if ($account === null || ! $account->isActive()) {
            throw BusinessRuleViolation::invalidField('financial_account_id', 'Ce compte n\'existe pas ou est inactif.');
        }

        if ($account->type !== self::ACCOUNT_TYPES[$method] || $account->currency !== $invoice->currency) {
            throw BusinessRuleViolation::invalidField('financial_account_id', "Le compte doit être de type « {$method} » et en {$invoice->currency} (PAY-004).", 'account_mismatch');
        }

        $payment = new CustomerPayment;
        $payment->fill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Receipt, $this->today()),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customerId,
            'financial_account_id' => $account->id,
            'method' => $method,
            'amount' => (string) $value,
            'paid_at' => $paidAt,
            'external_reference' => $externalReference,
            'status' => 'posted',
        ])->forceFill(['created_by' => $actorId])->save();

        // Facture d'abord : PAY-001 et PAY-003 refusent avant toute écriture financière.
        $this->invoices->recordPayment($invoice->id, $method, (string) $value);
        $movement = $this->ledger->post($account->id, 'customer_receipt', 'in', (string) $value, 'customer_payment', $payment->id, "Règlement {$payment->number} — facture {$invoice->number}", $paidAt);
        $payment->forceFill(['financial_movement_id' => $movement])->save();
        $this->audit->record('customer_payment.posted', 'customer_payment', $payment->id, [], [
            'number' => $payment->number, 'invoice' => $invoice->number, 'method' => $method, 'amount' => (string) $value,
        ]);

        return $payment->refresh();
    }

    private function reverseLocked(CustomerPayment $payment, string $reason, ?string $actorId): CustomerPayment
    {
        $now = CarbonImmutable::now();
        $movement = $this->ledger->reverse((string) $payment->financial_movement_id, $reason, $now);
        $reversal = new CustomerPayment;
        $reversal->fill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Receipt, $this->today()),
            'invoice_id' => $payment->invoice_id,
            'customer_id' => $payment->customer_id,
            'financial_account_id' => $payment->financial_account_id,
            'method' => $payment->method,
            'amount' => (string) Decimal::of($payment->amount)->negated(),
            'paid_at' => $now,
            'status' => 'posted',
            'reverses_payment_id' => $payment->id,
            'reversal_reason' => $reason,
            'financial_movement_id' => $movement,
        ])->forceFill(['created_by' => $actorId])->save();
        $payment->forceFill(['status' => 'reversed', 'updated_by' => $actorId])->save();

        $stillActive = CustomerPayment::query()->where('invoice_id', $payment->invoice_id)->where('status', 'posted')->where('amount', '>', 0)->exists();
        $this->invoices->recordPayment($payment->invoice_id, $payment->method, (string) $reversal->amount, clearMethod: ! $stillActive);
        $this->audit->record('customer_payment.reversed', 'customer_payment', $payment->id, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason, 'reversal' => $reversal->number]);

        return $reversal->refresh();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
