<?php

declare(strict_types=1);

namespace App\Modules\Costing;

use App\Modules\Costing\Application\CostingDefaults;
use App\Modules\Costing\Application\ProvisionalCosts;
use App\Modules\Logistics\Contracts\ProvisionalCostEstimator;
use App\Modules\Logistics\Contracts\ShipmentCloseGuard;
use App\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

final class CostingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProvisionalCostEstimator::class, ProvisionalCosts::class);
        $this->app->bind(ShipmentCloseGuard::class, ProvisionalCosts::class);
        $this->app->tag([CostingDefaults::class], CompanyProvisioningStep::TAG);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
