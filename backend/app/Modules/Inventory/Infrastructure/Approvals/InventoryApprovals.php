<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Approvals;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Inventory\Application\AdjustmentService;

final class InventoryApprovals implements ApprovableOperation
{
    public function __construct(private readonly AdjustmentService $adjustments) {}

    public function operations(): array
    {
        return ['stock_adjustment'];
    }

    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $this->adjustments->approve($subjectId, $actorId);
    }

    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void
    {
        $this->adjustments->reject($subjectId, $actorId);
    }
}
