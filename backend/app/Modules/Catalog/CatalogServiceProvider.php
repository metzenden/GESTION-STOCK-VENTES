<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Application\EloquentVariantCatalog;
use App\Modules\Catalog\Application\ProvisionCatalog;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VariantCatalog::class, EloquentVariantCatalog::class);
        $this->app->tag([ProvisionCatalog::class], CompanyProvisioningStep::TAG);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
