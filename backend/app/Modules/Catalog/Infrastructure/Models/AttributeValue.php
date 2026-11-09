<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $attribute_id
 * @property string $value
 * @property int $position
 */
class AttributeValue extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'attribute_values';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function auditSubjectType(): string
    {
        return 'attribute_value';
    }
}
