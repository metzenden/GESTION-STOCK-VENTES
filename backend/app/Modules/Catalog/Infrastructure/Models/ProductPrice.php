<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $variant_id
 * @property string $price_list_id
 * @property string $unit_id
 * @property string $price
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 */
class ProductPrice extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'product_prices';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }

    public function auditSubjectType(): string
    {
        return 'product_price';
    }
}
