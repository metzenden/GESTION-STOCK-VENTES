<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Mail\PasswordResetMail;
use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Traits\HasRoles;

/**
 * Utilisateur. Le rattachement à l'entreprise est immuable (déclencheur SQL) ;
 * company_id NULL ⇔ super-administrateur (CHECK).
 *
 * @property string $id
 * @property string|null $company_id
 * @property bool $is_super_admin
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $phone
 * @property UserStatus $status
 * @property string $theme
 * @property int $session_epoch
 * @property CarbonImmutable|null $last_login_at
 */
class User extends Authenticatable implements CompanyMember
{
    use Audited;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlidKey;

    protected $guarded = ['id', 'company_id', 'is_super_admin', 'session_epoch'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
            'session_epoch' => 'integer',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function memberId(): string
    {
        return $this->id;
    }

    public function memberCompanyId(): ?string
    {
        return $this->company_id;
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function auditSubjectType(): string
    {
        return 'user';
    }

    /** E-mail en français avec lien vers l'application (L1-06). */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->queue(new PasswordResetMail($this->name, $this->email, (string) $token));
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
