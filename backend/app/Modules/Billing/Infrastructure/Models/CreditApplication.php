<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $invoice_id
 * @property string $amount
 * @property CarbonImmutable $applied_at
 * @property string|null $reverses_application_id
 */
class CreditApplication extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    public const UPDATED_AT = null;

    protected $table = 'credit_applications';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }
}
