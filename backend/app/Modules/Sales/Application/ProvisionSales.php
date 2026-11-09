<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** Client « comptoir » de l'entreprise, utilisé par défaut pour la vente comptoir (SAL-008). */
final class ProvisionSales implements CompanyProvisioningStep
{
    public const WALK_IN_CODE = 'COMPTOIR';

    public function priority(): int
    {
        return 60;
    }

    public function provision(string $companyId): void
    {
        self::walkIn();
    }

    public static function walkIn(): Customer
    {
        return Customer::query()->firstOrCreate(['code' => self::WALK_IN_CODE], ['name' => 'Client comptoir', 'is_walk_in' => true, 'status' => 'active']);
    }
}
