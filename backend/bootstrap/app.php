<?php

declare(strict_types=1);

use App\Modules\Approvals\Http\Middleware\ExposeApprovalRequest;
use App\Modules\IdentityAccess\Infrastructure\Http\Middleware\EnsureSessionIsCurrent;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Infrastructure\Http\ProblemDetailsRenderer;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\ResolveCompanyContext;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignRequestId::class], append: [ExposeApprovalRequest::class]);
        $middleware->throttleApi('api');

        // SPA sur la même origine : sessions et CSRF Sanctum pour les requêtes issues du frontend (D-005).
        $middleware->statefulApi();

        // Contexte d'entreprise et validité de session avant tout contrôle de permission.
        $middleware->prependToPriorityList(Authorize::class, EnsureSessionIsCurrent::class);
        $middleware->prependToPriorityList(Authorize::class, ResolveCompanyContext::class);

        // Caddy et Nginx relaient les requêtes depuis le réseau Docker privé.
        $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Les violations de règles métier sont des réponses normales, pas des incidents.
        $exceptions->dontReport([BusinessRuleViolation::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ProblemDetailsRenderer::class)->render($e, $request);
        });
    })
    ->create();
