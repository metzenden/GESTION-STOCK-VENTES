<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

/** Cycle de vie d'une entreprise : provisioning → active ⇄ suspended → archived. */
enum CompanyStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function acceptsWrites(): bool
    {
        return $this === self::Active;
    }
}
