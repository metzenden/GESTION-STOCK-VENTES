<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/**
 * Enregistre une action dans le journal d'audit (L1-08, GEN-003).
 *
 * Par défaut, l'entreprise est l'entreprise active et l'auteur l'utilisateur connecté ;
 * $companyId et $userId permettent de les préciser hors requête (connexion échouée, tâche de fond).
 */
interface AuditRecorder
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?string $companyId = null,
        ?string $userId = null,
        bool $platform = false,
    ): void;
}
