<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $customer_type
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $default_price_list_id
 * @property int|null $payment_terms_days
 * @property bool $is_walk_in
 * @property string $status
 * @property bool $has_history
 * @property CarbonImmutable $created_at
 */
class Customer extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'customers';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer', 'is_walk_in' => 'boolean', 'has_history' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function auditSubjectType(): string
    {
        return 'customer';
    }
}
