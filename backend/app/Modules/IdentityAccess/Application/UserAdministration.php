<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;

/**
 * Gestion des utilisateurs de l'entreprise active (L1-13).
 *
 * - ADM-003 : désactiver révoque immédiatement toutes les sessions (époque incrémentée).
 * - ADM-005 : le dernier administrateur actif ne peut être ni désactivé ni privé du rôle administrateur.
 */
final class UserAdministration
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{name?: string, phone?: string|null, role_ids?: list<string>}  $changes
     */
    public function update(string $companyId, string $userId, array $changes, string $actorId): User
    {
        return $this->db->transaction(function () use ($companyId, $userId, $changes, $actorId) {
            $user = $this->lockUser($companyId, $userId);

            $user->fill(array_intersect_key($changes, array_flip(['name', 'phone'])));
            $user->forceFill(['updated_by' => $actorId])->save();

            if (array_key_exists('role_ids', $changes)) {
                $roles = Role::query()->where('company_id', $companyId)->whereKey($changes['role_ids'])->get();

                if ($roles->count() !== count(array_unique($changes['role_ids']))) {
                    throw new BusinessRuleViolation('validation_failed', 'Données invalides', 'Un des rôles n\'existe pas.', 422, [], [
                        ['pointer' => '/role_ids', 'code' => 'invalid', 'message' => 'Un des rôles n\'existe pas.'],
                    ]);
                }

                $keepsAdmin = $roles->contains('template_code', RoleTemplate::CompanyAdmin->value);

                if (! $keepsAdmin && $user->isActive() && $this->isAdmin($user)) {
                    $this->assertAnotherActiveAdmin($companyId, $user->id);
                }

                $before = $user->roles->pluck('id')->sort()->values()->all();
                $user->syncRoles($roles);
                $after = $roles->pluck('id')->sort()->values()->all();

                if ($before !== $after) {
                    $this->audit->record('user.roles_changed', 'user', $user->id,
                        ['role_ids' => $before], ['role_ids' => $after], companyId: $companyId);
                }
            }

            return $user->refresh();
        });
    }

    public function disable(string $companyId, string $userId, string $actorId): User
    {
        return $this->db->transaction(function () use ($companyId, $userId, $actorId) {
            $user = $this->lockUser($companyId, $userId);

            if ($user->status === UserStatus::Disabled) {
                throw BusinessRuleViolation::alreadyProcessed('Cet utilisateur est déjà désactivé.');
            }

            if ($user->id === $actorId) {
                throw new BusinessRuleViolation('cannot_disable_self', 'Action impossible', 'Vous ne pouvez pas désactiver votre propre compte.', 422);
            }

            if ($this->isAdmin($user)) {
                $this->assertAnotherActiveAdmin($companyId, $user->id);
            }

            $user->forceFill([
                'status' => UserStatus::Disabled,
                'session_epoch' => $user->session_epoch + 1,
                'remember_token' => null,
                'updated_by' => $actorId,
            ])->save();

            return $user;
        });
    }

    public function enable(string $companyId, string $userId, string $actorId): User
    {
        return $this->db->transaction(function () use ($companyId, $userId, $actorId) {
            $user = $this->lockUser($companyId, $userId);

            if ($user->status !== UserStatus::Disabled) {
                throw BusinessRuleViolation::alreadyProcessed('Cet utilisateur est déjà actif.');
            }

            $user->forceFill(['status' => UserStatus::Active, 'updated_by' => $actorId])->save();

            return $user;
        });
    }

    private function lockUser(string $companyId, string $userId): User
    {
        return User::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($userId);
    }

    private function isAdmin(User $user): bool
    {
        return $user->roles->contains('template_code', RoleTemplate::CompanyAdmin->value);
    }

    private function assertAnotherActiveAdmin(string $companyId, string $exceptUserId): void
    {
        $adminRoleId = Role::query()->where('company_id', $companyId)
            ->where('template_code', RoleTemplate::CompanyAdmin->value)->value('id');

        // Verrouille les administrateurs actifs : deux désactivations simultanées ne peuvent pas laisser l'entreprise sans administrateur.
        $others = User::query()
            ->where('company_id', $companyId)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($exceptUserId)
            ->whereIn('id', fn ($q) => $q->select('model_id')->from('model_has_roles')
                ->where('company_id', $companyId)->where('role_id', $adminRoleId))
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new BusinessRuleViolation(
                'last_admin',
                'Dernier administrateur',
                'Cette entreprise doit conserver au moins un administrateur actif.',
                422,
            );
        }
    }
}
