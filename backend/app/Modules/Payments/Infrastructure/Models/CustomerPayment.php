<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $invoice_id
 * @property string $customer_id
 * @property string $financial_account_id
 * @property string $method
 * @property string $amount
 * @property CarbonImmutable $paid_at
 * @property string|null $external_reference
 * @property string $status
 * @property string|null $reverses_payment_id
 * @property string|null $reversal_reason
 * @property string|null $financial_movement_id
 * @property CarbonImmutable $created_at
 */
class CustomerPayment extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'customer_payments';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
