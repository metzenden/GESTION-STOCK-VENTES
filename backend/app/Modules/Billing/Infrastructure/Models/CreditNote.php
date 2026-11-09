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
 * @property string|null $number
 * @property string $customer_id
 * @property string|null $origin_invoice_id
 * @property string|null $return_id
 * @property string $kind
 * @property string $reason
 * @property string $currency
 * @property string $total
 * @property string $amount_applied
 * @property string $amount_available
 * @property string $status
 * @property CarbonImmutable|null $issued_at
 * @property array<string, mixed>|null $company_snapshot
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class CreditNote extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'credit_notes';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'amount_available'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'company_snapshot' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class, 'credit_note_id')->orderBy('created_at')->orderBy('id');
    }
}
