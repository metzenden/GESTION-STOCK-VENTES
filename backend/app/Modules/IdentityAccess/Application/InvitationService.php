<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Contracts\Invitations;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Mail\InvitationMail;
use App\Modules\IdentityAccess\Infrastructure\Models\Invitation;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitations (ADM-002, L1-10) : jeton aléatoire envoyé par e-mail, seul son SHA-256 est stocké ;
 * valable 72 h, à usage unique, révocable. Une nouvelle invitation révoque la précédente.
 */
final class InvitationService implements Invitations
{
    public const VALIDITY_HOURS = 72;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    public function inviteCompanyAdmin(string $companyId, string $email): void
    {
        $role = Role::query()->where('company_id', $companyId)
            ->where('template_code', RoleTemplate::CompanyAdmin->value)->firstOrFail();

        $this->invite($companyId, $email, $role->id, null);
    }

    public function invite(string $companyId, string $email, string $roleId, ?string $invitedBy): Invitation
    {
        $email = Str::lower(trim($email));

        return $this->db->transaction(function () use ($companyId, $email, $roleId, $invitedBy) {
            // Le rôle doit appartenir à l'entreprise (la FK composite le garantit aussi en base).
            $role = Role::query()->where('company_id', $companyId)->whereKey($roleId)->first()
                ?? throw self::validation('role_id', 'Ce rôle n\'existe pas.');

            if (User::query()->where('email', $email)->exists()) {
                throw self::validation('email', 'Cette adresse e-mail est déjà utilisée par un compte.');
            }

            Invitation::query()->where(['company_id' => $companyId, 'email' => $email])
                ->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            $token = Str::random(48);
            $invitation = new Invitation;
            $invitation->forceFill([
                'company_id' => $companyId,
                'email' => $email,
                'role_id' => $role->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => Carbon::now()->addHours(self::VALIDITY_HOURS),
                'created_by' => $invitedBy,
            ])->save();

            $this->audit->record('invitation.created', 'invitation', $invitation->id, [], [
                'email' => $email, 'role_id' => $role->id,
            ], companyId: $companyId);

            $company = $this->companies->find($companyId);

            Mail::to($email)->queue(new InvitationMail(
                $company !== null ? $company->tradeName : '',
                $role->name,
                $token,
                $invitation->expires_at->setTimezone('Africa/Dakar')->format('d/m/Y à H:i'),
            ));

            return $invitation;
        });
    }

    public function revoke(string $companyId, string $invitationId): Invitation
    {
        return $this->db->transaction(function () use ($companyId, $invitationId) {
            $invitation = Invitation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invitationId);

            if ($invitation->status() !== 'pending') {
                throw BusinessRuleViolation::alreadyProcessed('Cette invitation n\'est plus en attente.');
            }

            $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
            $this->audit->record('invitation.revoked', 'invitation', $invitation->id, companyId: $companyId);

            return $invitation;
        });
    }

    /** Crée le compte rattaché à l'entreprise de l'invitation ; même erreur pour tout jeton inutilisable. */
    public function accept(string $token, string $name, string $password): User
    {
        return $this->db->transaction(function () use ($token, $name, $password) {
            $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($invitation === null || $invitation->status() !== 'pending') {
                throw new BusinessRuleViolation(
                    'invitation_invalid',
                    'Invitation invalide',
                    'Ce lien d\'invitation est invalide, expiré ou déjà utilisé.',
                    422,
                );
            }

            return $this->tenant->runAs($invitation->company_id, function () use ($invitation, $name, $password) {
                $user = new User;
                $user->forceFill([
                    'company_id' => $invitation->company_id,
                    'is_super_admin' => false,
                    'name' => $name,
                    'email' => $invitation->email,
                    'email_verified_at' => Carbon::now(),
                    'password' => $password,
                    'status' => UserStatus::Active,
                ]);

                try {
                    $user->save();
                } catch (UniqueConstraintViolationException) {
                    throw self::validation('email', 'Cette adresse e-mail est déjà utilisée par un compte.');
                }

                $user->assignRole(Role::query()->findOrFail($invitation->role_id));
                $invitation->forceFill(['accepted_at' => Carbon::now(), 'user_id' => $user->id])->save();

                $this->audit->record('invitation.accepted', 'invitation', $invitation->id, [], ['user_id' => $user->id],
                    companyId: $invitation->company_id, userId: $user->id);

                return $user;
            });
        });
    }

    private static function validation(string $field, string $message): BusinessRuleViolation
    {
        return new BusinessRuleViolation('validation_failed', 'Données invalides', $message, 422, [], [
            ['pointer' => '/'.$field, 'code' => 'invalid', 'message' => $message],
        ]);
    }
}
