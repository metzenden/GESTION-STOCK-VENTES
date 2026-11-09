<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $number
 * @property string $from_account_id
 * @property string $to_account_id
 * @property string $amount_from
 * @property string $amount_to
 * @property string|null $exchange_rate
 * @property CarbonImmutable $occurred_at
 * @property string|null $description
 * @property string $status
 * @property CarbonImmutable $created_at
 */
class AccountTransfer extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'account_transfers';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
