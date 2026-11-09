<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Rendu RFC 9457 (application/problem+json) de toutes les erreurs de l'API (docs/05 §1.6).
 * Aucun détail interne n'est exposé hors mode débogage.
 */
final class ProblemDetailsRenderer
{
    private const HTTP_CODES = [
        400 => ['bad_request', 'Requête invalide'],
        401 => ['unauthenticated', 'Authentification requise'],
        403 => ['forbidden', 'Action non autorisée'],
        404 => ['not_found', 'Ressource introuvable'],
        405 => ['method_not_allowed', 'Méthode non autorisée'],
        409 => ['conflict', 'Conflit'],
        413 => ['payload_too_large', 'Contenu trop volumineux'],
        415 => ['unsupported_media_type', 'Format non pris en charge'],
        419 => ['csrf_token_mismatch', 'Session expirée ou jeton CSRF invalide'],
        423 => ['company_suspended', 'Entreprise suspendue'],
        429 => ['too_many_requests', 'Trop de requêtes'],
        503 => ['service_unavailable', 'Service momentanément indisponible'],
    ];

    public function __construct(private readonly bool $debug) {}

    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title, $detail, $extra, $headers] = $this->describe($e);

        $body = [
            'type' => 'https://fds-business.app/errors/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
        ] + $extra;

        if ($this->debug && $status >= 500) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse(
            array_filter($body, static fn ($value) => $value !== null),
            $status,
            $headers + ['Content-Type' => 'application/problem+json'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array{int, string, string, string|null, array<string, mixed>, array<string, string>}
     */
    private function describe(Throwable $e): array
    {
        if ($e instanceof BusinessRuleViolation) {
            $extra = array_filter(['meta' => $e->meta ?: null, 'errors' => $e->errors ?: null]);

            return [$e->httpStatus, $e->errorCode, $e->title, $e->getMessage(), $extra, []];
        }

        if ($e instanceof ValidationException) {
            return [
                422,
                'validation_failed',
                'Données invalides',
                'Certaines données sont invalides. Corrigez les champs signalés.',
                ['errors' => $this->validationErrors($e)],
                [],
            ];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Authentification requise', 'Veuillez vous connecter.', [], []];
        }

        // Ressource absente ou d'une autre entreprise : même réponse (D-054).
        if ($e instanceof ModelNotFoundException) {
            return [404, 'not_found', 'Ressource introuvable', "La ressource demandée n'existe pas.", [], []];
        }

        if ($e instanceof AuthorizationException) {
            return [403, 'forbidden', 'Action non autorisée', "Vous n'avez pas la permission d'effectuer cette action.", [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $title] = self::HTTP_CODES[$status] ?? ['http_error', 'Erreur'];
            $detail = $status === 404 ? "La ressource demandée n'existe pas." : null;

            /** @var array<string, string> $headers */
            $headers = array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'Allow']));

            return [$status, $code, $title, $detail, [], $headers];
        }

        return [500, 'internal_error', 'Erreur technique', 'Une erreur technique est survenue. Elle a été enregistrée.', [], []];
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function validationErrors(ValidationException $e): array
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'pointer' => '/'.str_replace('.', '/', (string) $field),
                    'code' => 'invalid',
                    'message' => $message,
                ];
            }
        }

        return $errors;
    }
}
