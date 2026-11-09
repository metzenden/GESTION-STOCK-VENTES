<?php

declare(strict_types=1);

namespace App\Modules\Logistics;

use App\Modules\Inventory\Contracts\ExpectedStockSource;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Logistics\Infrastructure\Directory\EloquentShipmentBook;
use App\Modules\Logistics\Infrastructure\Directory\ShipmentExpectedStock;
use App\Modules\ModuleServiceProvider;

final class LogisticsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShipmentBook::class, EloquentShipmentBook::class);
        $this->app->tag([ShipmentExpectedStock::class], ExpectedStockSource::TAG);
        // ProvisionalCostEstimator et ShipmentCloseGuard sont fournis par le module Costing.
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
