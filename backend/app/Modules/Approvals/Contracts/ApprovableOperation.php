<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Contracts;

/** Exécution d'une opération approuvée, ou levée de ses effets provisoires (APR-005, APR-006). Étiqueté TAG. */
interface ApprovableOperation
{
    public const TAG = 'approvals.operations';

    /** @return list<string> opérations prises en charge */
    public function operations(): array;

    /** @param array<string, mixed> $context */
    public function approve(string $operation, string $subjectId, array $context, ?string $actorId): void;

    /** @param array<string, mixed> $context */
    public function reject(string $operation, string $subjectId, array $context, ?string $actorId): void;
}
