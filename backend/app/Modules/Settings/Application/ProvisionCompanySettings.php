<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Infrastructure\Models\CompanySetting;
use App\Modules\Shared\Infrastructure\Sequences\DocumentSequenceFormats;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** Étape ADM-001 : paramètres par défaut et formats de numérotation. Aucun taux de taxe n'est créé (M-01). */
final class ProvisionCompanySettings implements CompanyProvisioningStep
{
    public function __construct(private readonly DocumentSequenceFormats $formats) {}

    public function priority(): int
    {
        return 10;
    }

    public function provision(string $companyId): void
    {
        (new CompanySetting)->save();
        $this->formats->initialize($companyId);
    }
}
