<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** Étape ADM-001 : grilles tarifaires Détail (par défaut) et Gros, dans la devise de référence. */
final class ProvisionCatalog implements CompanyProvisioningStep
{
    public function __construct(private readonly CompanyDirectory $companies) {}

    public function priority(): int
    {
        return 30;
    }

    public function provision(string $companyId): void
    {
        $currency = $this->companies->find($companyId)->referenceCurrency ?? 'XOF';

        foreach ([['retail', 'Détail', true], ['wholesale', 'Gros', false]] as [$code, $name, $default]) {
            (new PriceList(['code' => $code, 'name' => $name, 'currency' => $currency, 'is_default' => $default]))->save();
        }
    }
}
