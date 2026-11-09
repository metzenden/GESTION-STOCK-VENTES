<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\Finance\Infrastructure\Models\AccountTransfer;
use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialAccount;
use App\Modules\Finance\Infrastructure\Models\FinancialCorrection;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Opérations de trésorerie du lot 5 : transferts entre comptes (FIN-004), dépenses (FIN-005),
 * corrections financières soumises à approbation (FIN-002 : aucun mouvement avant décision).
 */
final class TreasuryService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly FinancialLedger $ledger,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly ApprovalGate $approvals,
        private readonly AuditRecorder $audit,
    ) {}

    /** FIN-004 : deux mouvements dans une transaction ; taux obligatoire si les devises diffèrent. */
    public function transfer(string $fromId, string $toId, string $amount, ?string $rate, ?string $description, ?string $actorId): AccountTransfer
    {
        return $this->db->transaction(function () use ($fromId, $toId, $amount, $rate, $description, $actorId) {
            if ($fromId === $toId) {
                throw BusinessRuleViolation::invalidField('to_account_id', 'Les deux comptes doivent être différents.');
            }

            $from = FinancialAccount::query()->findOrFail($fromId);
            $to = FinancialAccount::query()->find($toId) ?? throw BusinessRuleViolation::invalidField('to_account_id', 'Ce compte n\'existe pas.');
            $value = Decimal::of($amount)->toScale(4, RoundingMode::HalfUp);

            if ($from->currency !== $to->currency && ($rate === null || ! Decimal::of($rate)->isPositive())) {
                throw BusinessRuleViolation::invalidField('exchange_rate', "Indiquez le taux {$from->currency} → {$to->currency}.", 'required');
            }

            $received = $from->currency === $to->currency ? $value : $value->multipliedBy(Decimal::of((string) $rate))->toScale(4, RoundingMode::HalfUp);
            $now = CarbonImmutable::now();

            $transfer = new AccountTransfer;
            $transfer->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::AccountTransfer, $this->today()),
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount_from' => (string) $value,
                'amount_to' => (string) $received,
                'exchange_rate' => $from->currency === $to->currency ? null : $rate,
                'occurred_at' => $now,
                'description' => $description,
                'status' => 'posted',
            ])->forceFill(['created_by' => $actorId])->save();

            $label = "Transfert {$transfer->number} {$from->name} → {$to->name}";
            $this->ledger->post($from->id, 'transfer_out', 'out', (string) $value, 'account_transfer', $transfer->id, $label, $now);
            $this->ledger->post($to->id, 'transfer_in', 'in', (string) $received, 'account_transfer', $transfer->id, $label, $now);
            $this->audit->record('account_transfer.posted', 'account_transfer', $transfer->id, [], ['number' => $transfer->number, 'amount' => (string) $value]);

            return $transfer->refresh();
        }, 3);
    }

    /** FIN-005 : mouvement « expense » ; jamais un frais d'approche (CR-012). */
    public function expense(string $categoryId, string $accountId, CarbonImmutable $date, string $label, string $amount, ?string $fileId, ?string $actorId): Expense
    {
        return $this->db->transaction(function () use ($categoryId, $accountId, $date, $label, $amount, $fileId, $actorId) {
            $category = ExpenseCategory::query()->where('is_active', true)->find($categoryId) ?? throw BusinessRuleViolation::invalidField('category_id', 'Cette catégorie n\'existe pas ou est inactive.');
            $account = FinancialAccount::query()->find($accountId) ?? throw BusinessRuleViolation::invalidField('financial_account_id', 'Ce compte n\'existe pas.');
            $value = Decimal::of($amount)->toScale(4, RoundingMode::HalfUp);

            $expense = new Expense;
            $expense->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Expense, $date),
                'category_id' => $category->id,
                'financial_account_id' => $account->id,
                'expense_date' => $date->toDateString(),
                'label' => $label,
                'amount' => (string) $value,
                'currency' => $account->currency,
                'attachment_file_id' => $fileId,
                'status' => 'posted',
            ])->forceFill(['created_by' => $actorId])->save();

            $movement = $this->ledger->post($account->id, 'expense', 'out', (string) $value, 'expense', $expense->id, "Dépense {$expense->number} — {$label}", $date->setTimeFrom(CarbonImmutable::now()));
            $expense->forceFill(['financial_movement_id' => $movement])->save();
            $this->audit->record('expense.posted', 'expense', $expense->id, [], ['number' => $expense->number, 'amount' => (string) $value, 'category' => $category->name]);

            return $expense->refresh();
        }, 3);
    }

    public function reverseExpense(string $id, string $reason, ?string $actorId, bool $approved = false): Expense
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId, $approved) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($id);

            if ($expense->status !== 'posted') {
                throw BusinessRuleViolation::alreadyProcessed('Cette dépense a déjà été contre-passée.');
            }

            if (! $approved && $this->approvals->requiresApproval('expense_reversal', ['amount' => $expense->amount])) {
                $this->approvals->submit('expense_reversal', 'expense', $expense->id, ['reason' => $reason], "Contre-passation de la dépense {$expense->number} : {$reason}");

                return $expense;
            }

            $this->ledger->reverse((string) $expense->financial_movement_id, $reason, CarbonImmutable::now());
            $expense->forceFill(['status' => 'reversed', 'reversal_reason' => $reason, 'updated_by' => $actorId])->save();
            $this->audit->record('expense.reversed', 'expense', $expense->id, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason]);

            return $expense;
        }, 3);
    }

    /** Correction financière (L5-03) : en attente si une règle d'approbation s'applique, sans aucun mouvement (FIN-002). */
    public function correction(string $accountId, string $direction, string $amount, string $reason, ?string $actorId): FinancialCorrection
    {
        return $this->db->transaction(function () use ($accountId, $direction, $amount, $reason, $actorId) {
            $account = FinancialAccount::query()->find($accountId) ?? throw BusinessRuleViolation::invalidField('financial_account_id', 'Ce compte n\'existe pas.');
            $correction = new FinancialCorrection;
            $correction->fill([
                'financial_account_id' => $account->id,
                'direction' => $direction,
                'amount' => (string) Decimal::of($amount)->toScale(4, RoundingMode::HalfUp),
                'reason' => $reason,
                'status' => 'pending_approval',
            ])->forceFill(['created_by' => $actorId])->save();

            if (! $this->approvals->requiresApproval('financial_correction', ['amount' => $correction->amount, 'direction' => $direction])) {
                $this->postCorrection($correction, $actorId);
            } else {
                // FIN-002 : aucun mouvement avant la décision.
                $this->approvals->submit('financial_correction', 'financial_correction', $correction->id, [], "Correction de {$correction->amount} ({$direction}) : {$reason}");
            }

            return $correction->refresh();
        }, 3);
    }

    /** Exécution d'une correction (directe ou à l'approbation, APR-005). */
    public function postCorrection(FinancialCorrection $correction, ?string $actorId): FinancialCorrection
    {
        $correction = FinancialCorrection::query()->lockForUpdate()->findOrFail($correction->id);

        if ($correction->status !== 'pending_approval') {
            throw BusinessRuleViolation::alreadyProcessed('Cette correction a déjà été traitée.');
        }

        $movement = $this->ledger->post($correction->financial_account_id, 'correction', $correction->direction, $correction->amount, 'financial_correction', $correction->id,
            "Correction : {$correction->reason}", CarbonImmutable::now());
        $correction->forceFill(['status' => 'posted', 'financial_movement_id' => $movement, 'posted_at' => CarbonImmutable::now(), 'updated_by' => $actorId])->save();
        $this->audit->record('financial_correction.posted', 'financial_correction', $correction->id, ['status' => 'pending_approval'], ['status' => 'posted', 'amount' => $correction->amount]);

        return $correction;
    }

    public function closeCorrection(string $id, string $status, ?string $actorId): FinancialCorrection
    {
        $correction = FinancialCorrection::query()->lockForUpdate()->findOrFail($id);

        if ($correction->status !== 'pending_approval') {
            throw BusinessRuleViolation::alreadyProcessed('Cette correction a déjà été traitée.');
        }

        $correction->forceFill(['status' => $status, 'updated_by' => $actorId])->save();
        $this->audit->record("financial_correction.{$status}", 'financial_correction', $correction->id, ['status' => 'pending_approval'], ['status' => $status]);

        return $correction;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
