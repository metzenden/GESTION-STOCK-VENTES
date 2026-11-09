<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Billing\Application\InvoicingService;
use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Billing\Infrastructure\Approvals\BillingApprovals;
use App\Modules\ModuleServiceProvider;

final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Invoicing::class, InvoicingService::class);
        $this->app->tag([BillingApprovals::class], ApprovableOperation::TAG);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
