<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Controllers;

use App\Modules\Shared\Infrastructure\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Santé publique minimale : ne révèle pas l'état détaillé des dépendances.
 */
final class HealthController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $healthy = $checker->healthy();

        return new JsonResponse(['status' => $healthy ? 'ok' : 'degraded'], $healthy ? 200 : 503);
    }
}
