<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rôle d'une entreprise ; company_id NULL = modèle système copié à la création d'une entreprise.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $name
 * @property bool $is_system
 * @property string|null $template_code
 */
class Role extends SpatieRole
{
    use Audited;
    use HasUlidKey;

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'role';
    }
}
