<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** Étape ADM-001 : copie des rôles par défaut dans la nouvelle entreprise. */
final class ProvisionCompanyRoles implements CompanyProvisioningStep
{
    public function __construct(private readonly RoleAdministration $roles) {}

    public function priority(): int
    {
        return 20;
    }

    public function provision(string $companyId): void
    {
        $this->roles->copyTemplates($companyId);
    }
}
