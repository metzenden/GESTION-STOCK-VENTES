<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $warehouse_id
 * @property string $scope
 * @property array<string, mixed>|null $scope_filter
 * @property string $status
 * @property CarbonImmutable|null $snapshot_at
 * @property CarbonImmutable|null $validated_at
 * @property string|null $adjustment_id
 * @property string|null $note
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class Inventory extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'inventories';

    /** Horodatages TIMESTAMP(6) : la microseconde compte (figeage d'inventaire, INV-102). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['scope_filter' => 'array', 'snapshot_at' => 'immutable_datetime', 'validated_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InventoryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryLine::class)->orderBy('variant_id')->orderBy('lot_id');
    }
}
