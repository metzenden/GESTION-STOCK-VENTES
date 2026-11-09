<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Http\Middleware;

use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Détermine l'entreprise active côté serveur (D-004, MT-007) :
 * utilisateur ordinaire → son entreprise ; super-admin → l'entreprise choisie, conservée en session.
 * Aucun company_id fourni par le client n'est lu.
 *
 * Paramètre « optional » : la route accepte l'absence d'entreprise active (routes de plateforme).
 */
final class ResolveCompanyContext
{
    public const SESSION_KEY = 'active_company_id';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();
        $companyId = null;

        if ($user instanceof CompanyMember) {
            if ($user->isSuperAdmin()) {
                $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
                $companyId = is_string($chosen) && $this->companies->find($chosen) !== null ? $chosen : null;
            } else {
                $companyId = $user->memberCompanyId();
            }
        }

        $this->tenant->set($companyId);

        if ($companyId === null && $mode !== 'optional') {
            throw new BusinessRuleViolation(
                'company_context_required',
                'Entreprise active requise',
                'Choisissez d\'abord une entreprise.',
                409,
            );
        }

        return $next($request);
    }
}
