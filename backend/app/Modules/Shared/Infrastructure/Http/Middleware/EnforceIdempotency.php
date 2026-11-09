<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Middleware;

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence des actions critiques (D-051) : une même clé rejoue la réponse initiale pendant 24 h.
 *
 * - Même clé, requête différente ⇒ 409 idempotency_conflict.
 * - Même clé pendant le traitement ⇒ 409 request_in_progress.
 * - Les réponses 5xx (et 401/419/429) ne sont pas mémorisées : la requête peut être rejouée.
 * - Une clé restée « en cours » plus de STALE_SECONDS (processus interrompu) peut être reprise ;
 *   les gardes métier (transitions conditionnelles, contraintes d'unicité) empêchent alors tout doublon.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    private const STALE_SECONDS = 120;

    private const NOT_STORED = [401, 419, 429];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');

        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            throw BusinessRuleViolation::idempotencyKeyRequired();
        }

        $userId = $request->user()?->getAuthIdentifier();
        $owner = $userId !== null ? 'user:'.$userId : 'anon:'.$request->ip();
        $route = $request->method().' '.($request->route()?->uri() ?? $request->path());
        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());

        $replay = $this->acquire($owner, $key, $route, $hash, $userId);

        if ($replay !== null) {
            return $replay;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->release($owner, $key);

            throw $e;
        }

        $this->complete($owner, $key, $response);

        return $response;
    }

    private function acquire(string $owner, string $key, string $route, string $hash, int|string|null $userId): ?Response
    {
        $now = Carbon::now();

        $inserted = $this->db->table('idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'company_id' => $this->tenant->companyId(),
            'user_id' => $userId !== null ? (string) $userId : null,
            'owner' => $owner,
            'idempotency_key' => $key,
            'route' => $route,
            'request_hash' => $hash,
            'status' => 'processing',
            'locked_at' => $now,
            'expires_at' => $now->copy()->addHours(self::TTL_HOURS),
            'created_at' => $now,
        ]);

        if ($inserted === 1) {
            return null;
        }

        $existing = $this->db->table('idempotency_keys')->where(['owner' => $owner, 'idempotency_key' => $key])->first();

        if ($existing === null || Carbon::parse($existing->expires_at)->isPast()) {
            $this->release($owner, $key);

            return $this->acquire($owner, $key, $route, $hash, $userId);
        }

        if ($existing->request_hash !== $hash || $existing->route !== $route) {
            throw BusinessRuleViolation::idempotencyConflict();
        }

        if ($existing->status === 'completed') {
            return $this->replay($existing);
        }

        // Clé « en cours » abandonnée : reprise atomique (une seule requête peut la reprendre).
        $takenOver = $this->db->table('idempotency_keys')
            ->where(['owner' => $owner, 'idempotency_key' => $key, 'status' => 'processing'])
            ->where('locked_at', '<', $now->copy()->subSeconds(self::STALE_SECONDS))
            ->update(['locked_at' => $now]);

        if ($takenOver === 1) {
            return null;
        }

        throw BusinessRuleViolation::requestInProgress();
    }

    private function complete(string $owner, string $key, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::NOT_STORED, true)) {
            $this->release($owner, $key);

            return;
        }

        $headers = array_filter([
            'Content-Type' => $response->headers->get('Content-Type'),
            'Location' => $response->headers->get('Location'),
        ]);

        $this->db->table('idempotency_keys')
            ->where(['owner' => $owner, 'idempotency_key' => $key])
            ->update([
                'status' => 'completed',
                'response_code' => $status,
                'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                'response_body' => (string) $response->getContent(),
                'locked_at' => null,
            ]);
    }

    private function release(string $owner, string $key): void
    {
        $this->db->table('idempotency_keys')->where(['owner' => $owner, 'idempotency_key' => $key])->delete();
    }

    private function replay(\stdClass $stored): Response
    {
        /** @var array<string, string> $headers */
        $headers = json_decode((string) $stored->response_headers, true, flags: JSON_THROW_ON_ERROR);

        return new Response((string) $stored->response_body, (int) $stored->response_code, $headers + [
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
