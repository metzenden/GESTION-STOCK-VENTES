<?php

declare(strict_types=1);

namespace App\Modules\Imports\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $import_type
 * @property string|null $supplier_id
 * @property string $name
 * @property string $file_format
 * @property array<string, mixed> $mapping
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ImportMappingTemplate extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'import_mapping_templates';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
