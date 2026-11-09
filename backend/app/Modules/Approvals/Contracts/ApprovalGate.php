<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Contracts;

/**
 * Point d'entrée des approbations (L1-15, L6-01) : les modules demandent si une opération doit être approuvée,
 * puis déposent la demande ; l'exécution se fait à la décision par l'ApprovableOperation du module (APR-005).
 */
interface ApprovalGate
{
    /**
     * Indique si l'opération doit être soumise à approbation avant exécution.
     *
     * @param  array<string, mixed>  $context  données utiles aux règles (montant, remise, quantité…)
     */
    public function requiresApproval(string $operation, array $context = []): bool;

    /**
     * Dépose une demande (une seule ouverte par sujet et opération, APR-010) ; renvoie son identifiant.
     *
     * @param  array<string, mixed>  $context  conservé et rendu à l'exécution (motif, paramètres…)
     */
    public function submit(string $operation, string $subjectType, string $subjectId, array $context, string $summary): string;

    /** Identifiant de la dernière demande déposée dans cette requête HTTP (pour la réponse). */
    public function lastRequestId(): ?string;

    /** Oublie l'état propre à la requête (début de requête ou de tâche). */
    public function reset(): void;
}
