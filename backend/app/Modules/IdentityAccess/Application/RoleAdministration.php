<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles de l'entreprise active (L1-07).
 *
 * - ADM-004 : aucune permission platform.* dans un rôle d'entreprise.
 * - Le rôle « Administrateur » est verrouillé : il porte toujours toutes les permissions d'entreprise.
 * - Un rôle attribué à un utilisateur ou à une invitation en attente ne peut être supprimé.
 */
final class RoleAdministration
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditRecorder $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /** @param list<string> $permissions */
    public function create(string $companyId, string $name, array $permissions, string $actorId): Role
    {
        $this->assertPermissions($permissions);
        $this->assertNameAvailable($companyId, $name, null);

        return $this->db->transaction(function () use ($companyId, $name, $permissions, $actorId) {
            $role = new Role;
            $role->forceFill([
                'company_id' => $companyId,
                'name' => $name,
                'guard_name' => 'web',
                'is_system' => false,
                'created_by' => $actorId,
            ])->save();

            $role->syncPermissions($permissions);
            $this->audit->record('role.permissions_changed', 'role', $role->id, [], ['permissions' => $permissions], companyId: $companyId);

            return $role;
        });
    }

    /** @param list<string>|null $permissions */
    public function update(string $companyId, string $roleId, ?string $name, ?array $permissions, string $actorId): Role
    {
        return $this->db->transaction(function () use ($companyId, $roleId, $name, $permissions, $actorId) {
            $role = $this->lockRole($companyId, $roleId);
            $this->assertEditable($role);

            if ($name !== null && $name !== $role->name) {
                $this->assertNameAvailable($companyId, $name, $role->id);
                $role->forceFill(['name' => $name, 'updated_by' => $actorId])->save();
            }

            if ($permissions !== null) {
                $this->assertPermissions($permissions);
                $before = $role->permissions->pluck('name')->sort()->values()->all();
                $role->syncPermissions($permissions);
                $after = collect($permissions)->unique()->sort()->values()->all();

                if ($before !== $after) {
                    $this->audit->record('role.permissions_changed', 'role', $role->id, ['permissions' => $before], ['permissions' => $after], companyId: $companyId);
                }
            }

            $this->registrar->forgetCachedPermissions();

            return $role->refresh();
        });
    }

    public function delete(string $companyId, string $roleId): void
    {
        $this->db->transaction(function () use ($companyId, $roleId) {
            $role = $this->lockRole($companyId, $roleId);

            if ($role->is_system) {
                throw self::locked();
            }

            $assigned = $this->db->table('model_has_roles')->where(['company_id' => $companyId, 'role_id' => $role->id])->exists()
                || $this->db->table('invitations')->where(['company_id' => $companyId, 'role_id' => $role->id])
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();

            if ($assigned) {
                throw new BusinessRuleViolation('role_in_use', 'Rôle utilisé', 'Ce rôle est attribué : retirez-le des utilisateurs avant de le supprimer.', 409);
            }

            // Les invitations closes conservent leur historique : on ne supprime que si aucune n'y fait référence.
            if ($this->db->table('invitations')->where('role_id', $role->id)->exists()) {
                throw new BusinessRuleViolation('has_history', 'Suppression impossible', 'Ce rôle a un historique d\'invitations : il ne peut pas être supprimé.', 422);
            }

            $role->delete();
            $this->registrar->forgetCachedPermissions();
        });
    }

    /** Copie des modèles système dans une nouvelle entreprise (ADM-001). */
    public function copyTemplates(string $companyId): void
    {
        foreach (RoleTemplate::cases() as $template) {
            $role = new Role;
            $role->forceFill([
                'company_id' => $companyId,
                'name' => $template->label(),
                'guard_name' => 'web',
                'is_system' => true,
                'template_code' => $template->value,
            ])->save();

            $role->syncPermissions(PermissionCatalog::forTemplate($template));
        }

        $this->registrar->forgetCachedPermissions();
    }

    private function lockRole(string $companyId, string $roleId): Role
    {
        return Role::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($roleId);
    }

    private function assertEditable(Role $role): void
    {
        if ($role->template_code === RoleTemplate::CompanyAdmin->value) {
            throw self::locked();
        }
    }

    /** @param list<string> $permissions */
    private function assertPermissions(array $permissions): void
    {
        foreach ($permissions as $index => $permission) {
            if (! PermissionCatalog::exists($permission) || PermissionCatalog::isPlatform($permission)) {
                $message = "La permission « {$permission} » n'est pas attribuable à un rôle d'entreprise.";

                throw new BusinessRuleViolation('validation_failed', 'Données invalides', $message, 422, [], [
                    ['pointer' => "/permissions/{$index}", 'code' => 'invalid', 'message' => $message],
                ]);
            }
        }
    }

    private function assertNameAvailable(string $companyId, string $name, ?string $exceptId): void
    {
        $taken = Role::query()->where('company_id', $companyId)->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw new BusinessRuleViolation('validation_failed', 'Données invalides', 'Un rôle porte déjà ce nom.', 422, [], [
                ['pointer' => '/name', 'code' => 'invalid', 'message' => 'Un rôle porte déjà ce nom.'],
            ]);
        }
    }

    private static function locked(): BusinessRuleViolation
    {
        return new BusinessRuleViolation('role_locked', 'Rôle verrouillé', 'Ce rôle système ne peut pas être modifié ni supprimé.', 409);
    }
}
