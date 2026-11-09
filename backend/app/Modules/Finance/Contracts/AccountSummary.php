<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

final readonly class AccountSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $type,
        public string $currency,
        public string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
