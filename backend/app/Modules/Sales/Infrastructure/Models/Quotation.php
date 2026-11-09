<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

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
 * @property string $customer_id
 * @property string $price_list_id
 * @property string|null $warehouse_id
 * @property string $currency
 * @property string|null $global_discount_type
 * @property string|null $global_discount_value
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable|null $valid_until
 * @property string|null $converted_order_id
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property-read Customer|null $customer
 */
class Quotation extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'quotations';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['issue_date' => 'immutable_date', 'valid_until' => 'immutable_date', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return HasMany<QuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('position');
    }
}
