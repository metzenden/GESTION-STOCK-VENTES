<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de corrélation : renvoyé dans X-Request-Id, ajouté aux journaux et aux erreurs.
 */
final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
