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
 * @property string $warehouse_id
 * @property string $mode
 * @property string $status
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $address
 * @property string|null $courier
 * @property CarbonImmutable|null $prepared_at
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_reason
 * @property int $attempt
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class Delivery extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'deliveries';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['prepared_at' => 'immutable_datetime', 'shipped_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'attempt' => 'integer', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<DeliveryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'delivery_id')->orderBy('created_at')->orderBy('id');
    }
}
