<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Money;

use App\Modules\Shared\Domain\ValueObjects\Currency;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** Devises connues (table globale currencies) et leur précision (XOF : 0 décimale). */
final class CurrencyRegistry
{
    /** @var array<string, Currency> */
    private array $loaded = [];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function of(string $code): Currency
    {
        if (! isset($this->loaded[$code])) {
            $decimals = $this->db->table('currencies')->where('code', $code)->value('decimals');

            if ($decimals === null) {
                throw new InvalidArgumentException("Devise inconnue : {$code}.");
            }

            $this->loaded[$code] = Currency::of($code, (int) $decimals);
        }

        return $this->loaded[$code];
    }
}
