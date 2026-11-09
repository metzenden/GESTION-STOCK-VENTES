<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** Étape ADM-001 : dépôt initial, défini comme dépôt de vente par défaut. */
final class ProvisionInventory implements CompanyProvisioningStep
{
    public function __construct(private readonly CompanyPreferences $preferences) {}

    public function priority(): int
    {
        return 40;
    }

    public function provision(string $companyId): void
    {
        $warehouse = new Warehouse(['code' => 'PRINCIPAL', 'name' => 'Dépôt principal', 'status' => 'active']);
        $warehouse->save();

        $this->preferences->setDefaultSalesWarehouse($warehouse->id);
    }
}
