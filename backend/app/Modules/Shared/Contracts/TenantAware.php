<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Tâche de fond exécutée pour le compte d'une entreprise (docs/02 §3.2).
 * Le middleware de file rétablit le contexte et refuse l'exécution si l'entreprise est suspendue.
 */
interface TenantAware
{
    public function tenantCompanyId(): string;

    /** Une tâche qui écrit des données métier est refusée pour une entreprise suspendue (GEN-004). */
    public function writesBusinessData(): bool;
}
