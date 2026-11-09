<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $path
 * @property bool $is_active
 */
class Category extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'categories';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'category';
    }
}
