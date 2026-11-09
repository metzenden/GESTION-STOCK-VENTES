<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Permissions;

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aligne la base sur le catalogue (permissions + modèles de rôles système, company_id NULL).
 * Idempotent : appelé par la migration initiale puis par toute migration qui ajoute des permissions.
 */
final class PermissionCatalogSynchronizer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function sync(): void
    {
        $this->db->transaction(function () {
            foreach (PermissionCatalog::definitions() as $name => $definition) {
                $this->db->table('permissions')->upsert([[
                    'name' => $name,
                    'guard_name' => 'web',
                    'module' => $definition['module'],
                    'label' => $definition['label'],
                    'is_sensitive' => $definition['sensitive'] ?? false,
                ]], ['name', 'guard_name'], ['module', 'label', 'is_sensitive']);
            }

            $permissionIds = $this->db->table('permissions')->pluck('id', 'name');

            foreach (RoleTemplate::cases() as $template) {
                $roleId = $this->db->table('roles')->whereNull('company_id')->where('template_code', $template->value)->value('id');

                if ($roleId === null) {
                    $roleId = strtoupper((string) Str::ulid());
                    $this->db->table('roles')->insert([
                        'id' => $roleId,
                        'company_id' => null,
                        'name' => $template->label(),
                        'guard_name' => 'web',
                        'is_system' => true,
                        'template_code' => $template->value,
                    ]);
                }

                $this->db->table('role_has_permissions')->where('role_id', $roleId)->delete();
                $this->db->table('role_has_permissions')->insert(array_map(
                    fn (string $permission) => ['permission_id' => $permissionIds[$permission], 'role_id' => $roleId],
                    PermissionCatalog::forTemplate($template),
                ));
            }
            // Le rôle Administrateur de chaque entreprise porte toujours toutes les permissions d'entreprise (D-074).
            $adminPermissions = array_map(fn (string $p) => $permissionIds[$p], PermissionCatalog::forTemplate(RoleTemplate::CompanyAdmin));

            foreach ($this->db->table('roles')->whereNotNull('company_id')->where('template_code', RoleTemplate::CompanyAdmin->value)->pluck('id') as $roleId) {
                $this->db->table('role_has_permissions')->insertOrIgnore(array_map(fn ($id) => ['permission_id' => $id, 'role_id' => $roleId], $adminPermissions));
            }
        });

        $this->registrar->forgetCachedPermissions();
    }
}
