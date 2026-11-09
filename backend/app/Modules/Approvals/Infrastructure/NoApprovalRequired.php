<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Infrastructure;

use App\Modules\Approvals\Contracts\ApprovalGate;
use LogicException;

/** Implémentation sans règle (tests unitaires isolés) : aucune opération n'exige d'approbation. */
final class NoApprovalRequired implements ApprovalGate
{
    public function requiresApproval(string $operation, array $context = []): bool
    {
        return false;
    }

    public function submit(string $operation, string $subjectType, string $subjectId, array $context, string $summary): string
    {
        throw new LogicException('Aucune approbation n\'est configurée.');
    }

    public function lastRequestId(): ?string
    {
        return null;
    }

    public function reset(): void {}
}
