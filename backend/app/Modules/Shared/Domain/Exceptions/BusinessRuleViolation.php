<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use DomainException;

/**
 * Violation d'une règle métier. Rendue en application/problem+json avec un code stable
 * (voir docs/05-contrats-api.md §1.6). Le message (detail) est destiné à l'utilisateur, en français.
 */
class BusinessRuleViolation extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{pointer?: string, code: string, message?: string, meta?: array<string, mixed>}>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $title,
        string $detail,
        public readonly int $httpStatus = 422,
        public readonly array $meta = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($detail);
    }

    public static function invalidTransition(string $subject, string $from, string $action): self
    {
        return new self(
            'invalid_transition',
            'Transition impossible',
            "L'action « {$action} » n'est pas autorisée pour {$subject} à l'état « {$from} ».",
            409,
            ['from' => $from, 'action' => $action],
        );
    }

    public static function alreadyProcessed(string $detail = 'Cette opération a déjà été effectuée.'): self
    {
        return new self('already_processed', 'Opération déjà effectuée', $detail, 409);
    }

    public static function versionMismatch(): self
    {
        return new self(
            'version_mismatch',
            'Document modifié entre-temps',
            'Ce document a été modifié par un autre utilisateur. Rechargez-le avant de recommencer.',
            412,
        );
    }

    public static function idempotencyKeyRequired(): self
    {
        return new self(
            'idempotency_key_required',
            "Clé d'idempotence requise",
            "L'en-tête Idempotency-Key (16 à 64 caractères alphanumériques) est obligatoire pour cette opération.",
            428,
        );
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            'idempotency_conflict',
            "Clé d'idempotence déjà utilisée",
            "Cette clé d'idempotence a déjà servi pour une requête différente.",
            409,
        );
    }

    public static function requestInProgress(): self
    {
        return new self(
            'request_in_progress',
            'Requête en cours de traitement',
            'Une requête identique est déjà en cours de traitement. Patientez quelques instants.',
            409,
        );
    }

    public static function invalidAllocationBasis(): self
    {
        return new self(
            'invalid_allocation_basis',
            'Base de répartition invalide',
            'La somme des volumes ou poids servant à la répartition doit être strictement positive.',
            422,
        );
    }

    /** Erreur de validation portant sur un champ (pointeur RFC 6901, ex. « lines/0/qty »). */
    public static function invalidField(string $field, string $message, string $code = 'invalid'): self
    {
        return new self('validation_failed', 'Données invalides', $message, 422, [], [
            ['pointer' => '/'.ltrim($field, '/'), 'code' => $code, 'message' => $message],
        ]);
    }

    /** Donnée de référence avec historique : suppression interdite (GEN-002, CAT-006). */
    public static function hasHistory(string $detail): self
    {
        return new self('has_history', 'Suppression impossible', $detail, 409);
    }

    /**
     * Disponible insuffisant (ST-006) : aucune réservation ni sortie partielle.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function insufficientStock(int $available, int $requested, array $meta = []): self
    {
        return new self(
            'insufficient_stock',
            'Stock insuffisant',
            "Disponible : {$available}, demandé : {$requested}.",
            409,
            ['available' => $available, 'requested' => $requested] + $meta,
        );
    }
}
