<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $supplier_id
 * @property string|null $supplier_invoice_ref
 * @property string|null $description
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $compensates_bill_id
 * @property string $currency
 * @property string $amount
 * @property string $amount_paid
 * @property string $balance
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable|null $due_date
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property-read Supplier|null $supplier
 */
class SupplierBill extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'supplier_bills';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'balance'];

    protected function casts(): array
    {
        return ['bill_date' => 'immutable_date', 'due_date' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
