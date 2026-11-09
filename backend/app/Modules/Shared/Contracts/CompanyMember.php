<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Utilisateur authentifié vu par les autres modules : son entreprise de rattachement
 * (null pour le super-administrateur) suffit à résoudre le contexte (D-004).
 */
interface CompanyMember
{
    public function memberId(): string;

    public function memberCompanyId(): ?string;

    public function isSuperAdmin(): bool;
}
