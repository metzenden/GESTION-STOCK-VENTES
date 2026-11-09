<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $supplier_id
 * @property string|null $supplier_reference
 * @property CarbonImmutable $order_date
 * @property string $currency
 * @property string|null $estimated_rate
 * @property string $status
 * @property string $total_amount
 * @property string|null $notes
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $closed_at
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property-read Supplier|null $supplier
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'purchase_orders';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('position');
    }
}
