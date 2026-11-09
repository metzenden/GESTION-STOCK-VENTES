<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\ValueObjects\LotCandidate;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/**
 * Affectation des lots (INV-002, D-011) : premier entré, premier sorti (date de réception puis numéro de lot),
 * parmi les lots ayant une quantité libre ; ou affectation manuelle contrôlée.
 */
final class FifoAllocator
{
    /**
     * @param  list<LotCandidate>  $candidates
     * @return array<string, int> lot → quantité, dans l'ordre FIFO
     */
    public function allocate(int $quantity, array $candidates): array
    {
        usort($candidates, fn (LotCandidate $a, LotCandidate $b) => [$a->receivedAt, $a->lotNumber] <=> [$b->receivedAt, $b->lotNumber]);

        $allocation = [];
        $remaining = $quantity;

        foreach ($candidates as $lot) {
            if ($remaining === 0) {
                break;
            }

            if ($lot->free <= 0) {
                continue;
            }

            $take = min($remaining, $lot->free);
            $allocation[$lot->lotId] = $take;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw BusinessRuleViolation::insufficientStock($quantity - $remaining, $quantity);
        }

        return $allocation;
    }

    /**
     * Affectation choisie par l'utilisateur : chaque lot doit être éligible et suffisant, le total exact.
     *
     * @param  array<string, int>  $manual  lot → quantité
     * @param  list<LotCandidate>  $candidates
     * @return array<string, int>
     */
    public function validateManual(int $quantity, array $manual, array $candidates): array
    {
        $free = [];

        foreach ($candidates as $lot) {
            $free[$lot->lotId] = $lot->free;
        }

        if (array_sum($manual) !== $quantity) {
            throw BusinessRuleViolation::invalidField('allocations', "L'affectation par lot doit totaliser exactement {$quantity}.");
        }

        foreach ($manual as $lotId => $qty) {
            if ($qty <= 0) {
                throw BusinessRuleViolation::invalidField('allocations', 'Chaque quantité affectée doit être positive.');
            }

            if (! isset($free[$lotId])) {
                throw BusinessRuleViolation::invalidField('allocations', 'Ce lot n\'est pas disponible dans ce dépôt (bloqué, en livraison ou épuisé).');
            }

            if ($qty > $free[$lotId]) {
                throw BusinessRuleViolation::insufficientStock($free[$lotId], $qty, ['lot_id' => $lotId]);
            }
        }

        return $manual;
    }
}
