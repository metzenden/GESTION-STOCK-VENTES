<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $inventory_id
 * @property string $variant_id
 * @property string|null $lot_id
 * @property int $qty_expected
 * @property int|null $qty_counted
 * @property string|null $counted_by
 * @property CarbonImmutable|null $counted_at
 * @property string|null $note
 */
class InventoryLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'inventory_lines';

    /** Horodatages TIMESTAMP(6) : la microseconde compte (figeage d'inventaire, INV-102). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['qty_expected' => 'integer', 'qty_counted' => 'integer', 'counted_at' => 'immutable_datetime'];
    }
}
