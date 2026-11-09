<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Money;

use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Brick\Math\RoundingMode;

/** Affichage des montants sur les documents : arrondi HALF_UP à la précision de la devise (CR-007), format français. */
final class MoneyFormatter
{
    private const SYMBOLS = ['XOF' => 'FCFA', 'EUR' => '€', 'USD' => '$', 'CNY' => '¥'];

    public function __construct(private readonly CurrencyRegistry $currencies) {}

    public function format(string $amount, string $currency): string
    {
        $decimals = max(0, $this->currencies->of($currency)->decimals);
        $value = (string) Decimal::of($amount)->toScale($decimals, RoundingMode::HalfUp);
        $negative = str_starts_with($value, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $head = strlen($int) % 3;
        $groups = $head > 0 ? [substr($int, 0, $head)] : [];
        $groups = array_merge($groups, $int === '' ? [] : str_split(substr($int, $head), 3));
        $grouped = implode("\u{202F}", array_filter($groups, fn ($g) => $g !== ''));

        return ($negative ? '-' : '').$grouped.($frac !== '' ? ','.$frac : '')."\u{00A0}".(self::SYMBOLS[$currency] ?? $currency);
    }
}
