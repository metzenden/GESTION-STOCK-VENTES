<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $reference
 * @property string $name
 * @property string|null $description
 * @property string|null $category_id
 * @property ProductType $product_type
 * @property string $base_unit_label
 * @property bool $has_variants
 * @property ProductStatus $status
 * @property bool $has_history
 * @property int $version
 */
class Product extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'products';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['status' => ProductStatus::class, 'product_type' => ProductType::class, 'has_variants' => 'boolean', 'has_history' => 'boolean', 'version' => 'integer'];
    }

    public function auditSubjectType(): string
    {
        return 'product';
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderByDesc('is_default')->orderBy('sku');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
