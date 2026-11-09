<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string $container_number
 * @property string|null $container_type
 * @property string|null $seal_number
 */
class Container extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'containers';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [];
    }
}
