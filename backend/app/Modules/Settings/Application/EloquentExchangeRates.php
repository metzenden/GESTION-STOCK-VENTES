<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Contracts\ExchangeRates;
use App\Modules\Settings\Infrastructure\Models\ExchangeRate;
use DateTimeInterface;

final class EloquentExchangeRates implements ExchangeRates
{
    public function latest(string $from, string $to, DateTimeInterface $on): ?array
    {
        $rate = ExchangeRate::query()->where('from_currency', $from)->where('to_currency', $to)
            ->where('valid_on', '<=', $on->format('Y-m-d'))->orderByDesc('valid_on')->first();

        return $rate === null ? null : ['rate' => (string) $rate->rate, 'valid_on' => $rate->valid_on->toDateString()];
    }
}
