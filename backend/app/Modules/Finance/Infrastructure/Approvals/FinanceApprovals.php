<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Approvals;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Finance\Application\TreasuryService;
use App\Modules\Finance\Infrastructure\Models\FinancialCorrection;

final class FinanceApprovals implements ApprovableOperation
{
    public function __construct(private readonly TreasuryService $treasury) {}

    public function operations(): array
    {
        return ['financial_correction', 'expense_reversal'];
    }

    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $operation === 'financial_correction'
            ? $this->treasury->postCorrection(FinancialCorrection::query()->findOrFail($subjectId), $actorId)
            : $this->treasury->reverseExpense($subjectId, (string) ($context['reason'] ?? 'Approuvée'), $actorId, approved: true);
    }

    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        if ($operation === 'financial_correction') {
            $this->treasury->closeCorrection($subjectId, 'rejected', $actorId);
        }
    }
}
