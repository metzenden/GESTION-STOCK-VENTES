<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $product_id
 * @property string $sku
 * @property string $label
 * @property bool $is_default
 * @property int|null $pieces_per_carton
 * @property int|null $low_stock_threshold
 * @property string $status
 */
class ProductVariant extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'product_variants';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'pieces_per_carton' => 'integer', 'low_stock_threshold' => 'integer'];
    }

    public function auditSubjectType(): string
    {
        return 'product_variant';
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<VariantUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(VariantUnit::class, 'variant_id')->orderBy('factor');
    }

    /** @return BelongsToMany<AttributeValue, $this> */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values', 'variant_id', 'attribute_value_id')
            ->withPivot('company_id');
    }
}
