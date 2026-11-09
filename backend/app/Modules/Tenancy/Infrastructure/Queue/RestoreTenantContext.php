<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Shared\Contracts\TenantAware;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\EnsureCompanyWritable;
use Closure;

/**
 * Étape du bus de commandes (tâches de fond comprises) : rétablit l'entreprise d'une tâche TenantAware
 * et refuse une tâche d'écriture pour une entreprise suspendue (docs/02 §3.2, GEN-004).
 */
final class RestoreTenantContext
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    public function handle(object $command, Closure $next): mixed
    {
        if (! $command instanceof TenantAware) {
            return $next($command);
        }

        $company = $this->companies->find($command->tenantCompanyId());

        if ($company === null) {
            throw new \LogicException('Tâche refusée : entreprise inconnue.');
        }

        if ($command->writesBusinessData() && $company->status !== 'active') {
            throw EnsureCompanyWritable::suspended();
        }

        return $this->tenant->runAs($company->id, fn () => $next($command));
    }
}
