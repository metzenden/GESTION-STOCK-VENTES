<?php

declare(strict_types=1);

namespace App\Modules\Procurement;

use App\Modules\ModuleServiceProvider;
use App\Modules\Procurement\Application\SupplierBillService;
use App\Modules\Procurement\Contracts\PurchaseOrderBook;
use App\Modules\Procurement\Contracts\SupplierCommitments;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Procurement\Infrastructure\Directory\EloquentPurchaseOrderBook;
use App\Modules\Procurement\Infrastructure\Directory\EloquentSupplierDirectory;

final class ProcurementServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupplierDirectory::class, EloquentSupplierDirectory::class);
        $this->app->bind(PurchaseOrderBook::class, EloquentPurchaseOrderBook::class);
        $this->app->bind(SupplierCommitments::class, SupplierBillService::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
