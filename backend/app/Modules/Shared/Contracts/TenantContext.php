<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Entreprise active de la requête ou du job en cours, toujours déterminée côté serveur (D-004).
 * Sa résolution (utilisateur, sélecteur du super-administrateur) est assurée par le module Tenancy.
 */
interface TenantContext
{
    public function companyId(): ?string;

    /** Lève une exception si aucune entreprise n'est active (comportement « fail closed »). */
    public function requireCompanyId(): string;

    public function set(?string $companyId): void;

    /**
     * Exécute un traitement dans le contexte d'une entreprise puis restaure le contexte précédent.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $companyId, callable $callback): mixed;
}
