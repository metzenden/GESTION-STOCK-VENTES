<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $sales_order_id
 * @property string $customer_id
 * @property string $warehouse_id
 * @property string $return_type
 * @property string $status
 * @property string|null $credit_note_id
 * @property string|null $exchange_order_id
 * @property string|null $reason
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $closed_at
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class SalesReturn extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'returns';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id')->orderBy('created_at')->orderBy('id');
    }
}
