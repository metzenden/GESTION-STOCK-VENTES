<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation (ADM-002). Lue sans portée d'entreprise lors de l'acceptation (jeton public) ;
 * les écrans de l'entreprise filtrent explicitement sur l'entreprise active.
 *
 * @property string $id
 * @property string $company_id
 * @property string $email
 * @property string $role_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $user_id
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 */
class Invitation extends Model
{
    use HasUlidKey;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
