<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property bool $is_default
 */
class PriceList extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'price_lists';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'price_list';
    }
}
