<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** Origine d'un mouvement : document et ligne (unicité garantie en base, IMP-004). */
final readonly class StockSource
{
    public function __construct(
        public string $type,
        public string $id,
        public string $lineId,
        public ?string $reason = null,
    ) {}
}
