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
 * @property string|null $shipment_id
 * @property string|null $purchase_order_id
 * @property string $warehouse_id
 * @property string $status
 * @property string $counting_mode
 * @property string|null $note
 * @property CarbonImmutable|null $validated_at
 * @property string|null $validated_by
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class GoodsReceipt extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'goods_receipts';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['validated_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id')->orderBy('created_at')->orderBy('id');
    }
}
