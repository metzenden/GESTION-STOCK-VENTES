<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

interface Invitations
{
    /**
     * Invite l'administrateur d'une entreprise (rôle « Administrateur » de cette entreprise).
     * L'e-mail part uniquement après validation de la transaction englobante (ADM-001).
     */
    public function inviteCompanyAdmin(string $companyId, string $email): void;
}
