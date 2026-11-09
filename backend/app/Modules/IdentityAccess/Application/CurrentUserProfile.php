<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyDirectory;

/** Contenu de GET /auth/me : utilisateur, entreprise active, permissions effectives, préférences. */
final class CurrentUserProfile
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $companyId = $this->tenant->companyId();
        $company = $companyId !== null ? $this->companies->find($companyId) : null;

        if ($user->is_super_admin) {
            $permissions = $company !== null ? PermissionCatalog::names() : array_values(array_filter(PermissionCatalog::names(), PermissionCatalog::isPlatform(...)));
        } else {
            $permissions = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
                'theme' => $user->theme,
            ],
            'company' => $company?->toArray(),
            'permissions' => array_values($permissions),
        ];
    }
}
