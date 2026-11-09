<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Contracts\CompanySummary;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\ResolveCompanyContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Choix de l'entreprise active par le super-admin, conservé en session serveur et audité (MT-008). */
final class CompanyContextSwitcher
{
    public function __construct(
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    public function switch(Session $session, ?string $companyId): ?CompanySummary
    {
        $previous = $session->get(ResolveCompanyContext::SESSION_KEY);
        $company = null;

        if ($companyId !== null) {
            $company = $this->companies->find($companyId) ?? throw new ModelNotFoundException;
            $session->put(ResolveCompanyContext::SESSION_KEY, $company->id);
        } else {
            $session->forget(ResolveCompanyContext::SESSION_KEY);
        }

        $this->audit->record(
            'context.company_switched',
            'company',
            $company?->id,
            ['company_id' => is_string($previous) ? $previous : null],
            ['company_id' => $company?->id],
            platform: true,
        );

        return $company;
    }
}
