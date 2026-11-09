<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\Finance\Application\DatabaseFinancialLedger;
use App\Modules\Finance\Application\ProvisionFinance;
use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\Finance\Infrastructure\Approvals\FinanceApprovals;
use App\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;

final class FinanceServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinancialLedger::class, DatabaseFinancialLedger::class);
        $this->app->tag([ProvisionFinance::class], CompanyProvisioningStep::TAG);
        $this->app->tag([FinanceApprovals::class], ApprovableOperation::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        // Consultation de la liste des comptes : encaissement, dépenses ou consultation des soldes.
        Gate::define('any-finance-access', fn (Authorizable $user) => $user->can('customer_payments.create')
            || $user->can('financial_accounts.view_sensitive') || $user->can('expenses.manage'));
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
