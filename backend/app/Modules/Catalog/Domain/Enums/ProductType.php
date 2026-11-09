<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

/** Produit stocké ou prestation sans stock (D-050). */
enum ProductType: string
{
    case Stock = 'stock';
    case Service = 'service';
}
