<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string|null $reference
 * @property string|null $carrier
 * @property string|null $origin_country
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $eta
 * @property CarbonImmutable|null $arrived_at
 * @property string $status
 * @property string|null $default_warehouse_id
 * @property string|null $notes
 * @property CarbonImmutable|null $closed_at
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class Shipment extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'shipments';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_date', 'eta' => 'immutable_date', 'arrived_at' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ShipmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id')->orderBy('position');
    }

    /** @return HasMany<Container, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(Container::class, 'shipment_id')->orderBy('container_number');
    }
}
