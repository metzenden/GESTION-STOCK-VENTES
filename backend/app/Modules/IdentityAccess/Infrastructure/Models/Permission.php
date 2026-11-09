<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permission du catalogue global (docs/03 §3).
 *
 * @property int $id
 * @property string $name
 * @property string $module
 * @property string $label
 * @property bool $is_sensitive
 */
class Permission extends SpatiePermission
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }
}
