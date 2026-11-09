<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
