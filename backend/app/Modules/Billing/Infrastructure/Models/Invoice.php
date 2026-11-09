<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Models;

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
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $due_date
 * @property string $currency
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property string $amount_paid
 * @property string $amount_credited
 * @property string $balance_due
 * @property string|null $payment_method
 * @property string $payment_status
 * @property string $status
 * @property array<string, mixed> $customer_snapshot
 * @property array<string, mixed> $company_snapshot
 * @property CarbonImmutable $created_at
 */
class Invoice extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'invoices';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'balance_due'];

    protected function casts(): array
    {
        return ['issue_date' => 'immutable_date', 'due_date' => 'immutable_date', 'customer_snapshot' => 'array', 'company_snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('position');
    }
}
