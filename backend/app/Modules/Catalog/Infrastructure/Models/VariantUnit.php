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
 * @property string $company_id
 * @property string $variant_id
 * @property string $code
 * @property string $label
 * @property int $factor
 * @property string|null $barcode
 * @property bool $is_sellable
 * @property CarbonImmutable|null $locked_at
 */
class VariantUnit extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'variant_units';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['factor' => 'integer', 'is_sellable' => 'boolean', 'locked_at' => 'immutable_datetime'];
    }

    public function auditSubjectType(): string
    {
        return 'variant_unit';
    }
}
