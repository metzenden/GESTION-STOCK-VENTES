<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

/** Taux de taxe saisis par l'entreprise (aucun taux par défaut, BIL-005, VR-01). */
interface TaxRates
{
    /** @return array{id: string, name: string, rate: string, is_active: bool}|null */
    public function find(string $taxRateId): ?array;
}
