<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\ModuleServiceProvider;
use App\Modules\Sales\Application\ProvisionSales;
use App\Modules\Sales\Application\QuotationService;
use App\Modules\Sales\Contracts\CustomerDirectory;
use App\Modules\Sales\Infrastructure\Approvals\SalesApprovals;
use App\Modules\Sales\Infrastructure\Directory\EloquentCustomerDirectory;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ProvisionSales::class], CompanyProvisioningStep::TAG);
        $this->app->bind(CustomerDirectory::class, EloquentCustomerDirectory::class);
        $this->app->tag([SalesApprovals::class], ApprovableOperation::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        // Consultation des commandes, livraisons et retours : vente, préparation ou facturation.
        Gate::define('sales.read', fn (Authorizable $user) => $user->can('sales.create') || $user->can('deliveries.prepare')
            || $user->can('deliveries.handover') || $user->can('invoices.view'));
        Gate::define('counter.sell', fn (Authorizable $user) => $user->can('sales.confirm') && $user->can('deliveries.handover'));

        if ($this->app->runningInConsole()) {
            // Devis envoyés dont la validité est dépassée (docs/04 §8.1), entreprise par entreprise.
            Artisan::command('sales:expire-quotations', function (QuotationService $quotations, TenantContext $tenant, CompanyDirectory $companies) {
                $count = 0;

                foreach (DB::table('companies')->where('status', 'active')->pluck('id') as $companyId) {
                    $timezone = $companies->find((string) $companyId)->timezone ?? 'Africa/Dakar';
                    $count += $tenant->runAs((string) $companyId, fn () => $quotations->expireOutdated(CarbonImmutable::now($timezone)));
                }

                $this->info("{$count} devis expiré(s).");
            })->purpose('Passe à « expiré » les devis envoyés dont la date de validité est dépassée');

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('sales:expire-quotations')
                ->dailyAt('00:15')->timezone('Africa/Dakar')->name('sales-expire-quotations')->withoutOverlapping());
        }
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
