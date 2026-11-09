<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Approvals;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Sales\Application\SalesOrderService;

/** Effets des décisions d'approbation sur les commandes (remise, prix modifié, annulation). */
final class SalesApprovals implements ApprovableOperation
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public function operations(): array
    {
        return ['sales_order', 'sales_order_cancellation'];
    }

    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $operation === 'sales_order'
            ? $this->orders->approvePending($subjectId, $actorId)
            : $this->orders->cancel($subjectId, (string) ($context['reason'] ?? 'Annulation approuvée'), $actorId, approved: true);
    }

    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        if ($operation === 'sales_order') {
            $this->orders->rejectPending($subjectId, $actorId);
        }
    }
}
