<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Approvals;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Payments\Application\PaymentService;

final class PaymentApprovals implements ApprovableOperation
{
    public function __construct(private readonly PaymentService $payments) {}

    public function operations(): array
    {
        return ['payment_reversal', 'payment_method_change'];
    }

    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $operation === 'payment_reversal'
            ? $this->payments->reverse($subjectId, (string) ($context['reason'] ?? 'Approuvée'), $actorId, approved: true)
            : $this->payments->changeMethod($subjectId, (string) $context['method'], (string) $context['account_id'], (string) ($context['reason'] ?? ''), $actorId, approved: true);
    }

    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void {}
}
