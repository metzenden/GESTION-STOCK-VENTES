<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Inventory\Application\ProvisionInventory;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Inventory\Infrastructure\Approvals\InventoryApprovals;
use App\Modules\Inventory\Infrastructure\Console\ReconcileStockCommand;
use App\Modules\Inventory\Infrastructure\Directory\EloquentWarehouseDirectory;
use App\Modules\Inventory\Infrastructure\Ledger\DatabaseStockLedger;
use App\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Illuminate\Console\Scheduling\Schedule;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockLedger::class, DatabaseStockLedger::class);
        $this->app->bind(WarehouseDirectory::class, EloquentWarehouseDirectory::class);
        $this->app->tag([InventoryApprovals::class], ApprovableOperation::TAG);
        $this->app->tag([ProvisionInventory::class], CompanyProvisioningStep::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileStockCommand::class]);

            // INV-008 : rapprochement nocturne (heure de Dakar).
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('inventory:reconcile')
                ->dailyAt('02:30')->timezone('Africa/Dakar')->name('inventory-reconcile')->withoutOverlapping());
        }
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
