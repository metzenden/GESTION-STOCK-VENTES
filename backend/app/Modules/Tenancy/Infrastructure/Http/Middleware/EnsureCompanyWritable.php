<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Http\Middleware;

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute écriture métier dans une entreprise suspendue, y compris pour le super-admin
 * (GEN-004, D-041, TEST-018). Les lectures (GET, HEAD) restent possibles.
 */
final class EnsureCompanyWritable
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $company = $this->companies->find($this->tenant->requireCompanyId());

        if ($company === null || $company->status !== 'active') {
            throw self::suspended();
        }

        return $next($request);
    }

    public static function suspended(): BusinessRuleViolation
    {
        return new BusinessRuleViolation(
            'company_suspended',
            'Entreprise suspendue',
            'Cette entreprise est suspendue : les données sont consultables mais aucune modification n\'est possible.',
            423,
        );
    }
}
