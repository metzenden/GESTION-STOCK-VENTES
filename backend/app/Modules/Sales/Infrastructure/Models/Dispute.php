<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $sales_order_id
 * @property string $status
 * @property string $reason
 * @property string|null $resolution
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 */
class Dispute extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'disputes';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
