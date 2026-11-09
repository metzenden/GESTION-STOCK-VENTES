<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

use DateTimeInterface;

/** Taux de change saisis par l'entreprise active (aucun taux n'est inventé ni importé automatiquement). */
interface ExchangeRates
{
    /**
     * Dernier taux connu de `from` vers `to` valable au plus tard à cette date.
     *
     * @return array{rate: string, valid_on: string}|null
     */
    public function latest(string $from, string $to, DateTimeInterface $on): ?array;
}
