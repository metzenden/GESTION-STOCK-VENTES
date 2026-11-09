<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string $file_id
 * @property string|null $thumb_file_id
 * @property int $position
 */
class ProductImage extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'product_images';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function auditSubjectType(): string
    {
        return 'product_image';
    }
}
