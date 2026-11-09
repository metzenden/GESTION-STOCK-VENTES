<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

/**
 * Étape de la création atomique d'une entreprise (ADM-001), exécutée dans la transaction de création
 * avec l'entreprise comme contexte actif. Les modules s'enregistrent avec le tag ci-dessous.
 */
interface CompanyProvisioningStep
{
    public const TAG = 'company.provisioning';

    /** Ordre d'exécution croissant (les rôles avant l'invitation, par exemple). */
    public function priority(): int;

    public function provision(string $companyId): void;
}
