<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Enums;

/** Modèles de rôles système, copiés dans chaque entreprise à sa création (ADM-001, D-006). */
enum RoleTemplate: string
{
    case CompanyAdmin = 'company_admin';
    case Seller = 'seller';
    case Storekeeper = 'storekeeper';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::CompanyAdmin => 'Administrateur',
            self::Seller => 'Vendeur',
            self::Storekeeper => 'Magasinier',
            self::Accountant => 'Comptable',
        };
    }
}
