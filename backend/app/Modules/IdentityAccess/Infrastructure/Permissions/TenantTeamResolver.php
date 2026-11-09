<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Permissions;

use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * L'équipe spatie est toujours l'entreprise active du contexte serveur (D-004, D-006) :
 * aucune valeur fournie par le client ne peut la modifier.
 */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): ?string
    {
        return app(TenantContext::class)->companyId();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        app(TenantContext::class)->set($id instanceof Model ? (string) $id->getKey() : ($id === null ? null : (string) $id));
    }
}
