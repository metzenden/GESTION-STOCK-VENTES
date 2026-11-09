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
 * @property string $shipment_id
 * @property string|null $supplier_id
 * @property string $file_id
 * @property string|null $mapping_template_id
 * @property string $file_format
 * @property string $currency
 * @property string $status
 * @property array<string, mixed>|null $parse_report
 * @property CarbonImmutable|null $validated_at
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class PackingList extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'packing_lists';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['parse_report' => 'array', 'validated_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<PackingListLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PackingListLine::class, 'packing_list_id')->orderBy('row_number');
    }
}
