<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Approvals;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Billing\Application\InvoicingService;
use App\Modules\Billing\Infrastructure\Models\CreditNote;

final class BillingApprovals implements ApprovableOperation
{
    public function __construct(private readonly InvoicingService $invoicing) {}

    public function operations(): array
    {
        return ['credit_note'];
    }

    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $this->invoicing->issueNote(CreditNote::query()->findOrFail($subjectId), $actorId, approved: true);
    }

    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $this->invoicing->rejectNote($subjectId, $actorId);
    }
}
