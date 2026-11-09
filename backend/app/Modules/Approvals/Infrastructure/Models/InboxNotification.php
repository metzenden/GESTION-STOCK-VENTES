<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property string|null $link
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
class InboxNotification extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    public const UPDATED_AT = null;

    protected $table = 'notifications_inbox';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
