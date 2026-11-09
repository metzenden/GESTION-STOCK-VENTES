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
 * @property string $from_warehouse_id
 * @property string $to_warehouse_id
 * @property string $status
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $received_at
 * @property string|null $note
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class StockTransfer extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'stock_transfers';

    /** Horodatages TIMESTAMP(6) : la microseconde compte (figeage d'inventaire, INV-102). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id')->orderBy('created_at')->orderBy('id');
    }
}
