<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Contracts\TaxRates;
use App\Modules\Settings\Infrastructure\Models\TaxRate;

final class EloquentTaxRates implements TaxRates
{
    public function find(string $taxRateId): ?array
    {
        $rate = TaxRate::query()->find($taxRateId);

        return $rate === null ? null : ['id' => $rate->id, 'name' => $rate->name, 'rate' => (string) $rate->rate, 'is_active' => (bool) $rate->is_active];
    }
}
