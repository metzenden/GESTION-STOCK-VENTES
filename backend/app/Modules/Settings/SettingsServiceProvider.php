<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Modules\ModuleServiceProvider;
use App\Modules\Settings\Application\EloquentCompanyPreferences;
use App\Modules\Settings\Application\EloquentExchangeRates;
use App\Modules\Settings\Application\EloquentTaxRates;
use App\Modules\Settings\Application\ProvisionCompanySettings;
use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Settings\Contracts\ExchangeRates;
use App\Modules\Settings\Contracts\TaxRates;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

final class SettingsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ProvisionCompanySettings::class], CompanyProvisioningStep::TAG);
        $this->app->bind(CompanyPreferences::class, EloquentCompanyPreferences::class);
        $this->app->bind(ExchangeRates::class, EloquentExchangeRates::class);
        $this->app->bind(TaxRates::class, EloquentTaxRates::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
