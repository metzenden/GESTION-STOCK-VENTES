<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Http\Middleware;

use App\Modules\Shared\Contracts\CompanyMember;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes de plateforme : réservées au super-administrateur (ADM-004, TEST-002). */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof CompanyMember || ! $user->isSuperAdmin()) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
