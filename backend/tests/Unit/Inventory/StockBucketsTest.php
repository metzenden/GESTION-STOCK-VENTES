<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\ValueObjects\StockBuckets;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/*
| ST-007, C-07 : physique = disponible + réservé + bloqué + en livraison, chaque terme ≥ 0, quelles que soient les opérations.
*/

it('enchaîne réception, réservation, expédition et remise', function () {
    $s = (new StockBuckets)->receive(15)->reserve(15)->ship(15)->deliver(15);

    expect([$s->onHand, $s->reserved, $s->inDelivery, $s->available()])->toBe([0, 0, 0, 0]);
});

it('refuse une réservation supérieure au disponible, sans réservation partielle (ST-006)', function () {
    (new StockBuckets(10, 4, 2, 1))->reserve(4);
})->throws(BusinessRuleViolation::class, 'Disponible : 3, demandé : 4.');

it('remet en réservé une livraison échouée', function () {
    $s = (new StockBuckets)->receive(5)->reserve(5)->ship(3)->failDelivery(3);

    expect([$s->reserved, $s->inDelivery, $s->available()])->toBe([5, 0, 0]);
});

it('préserve l\'invariant sur 1 000 séquences aléatoires de 1 000 opérations', function () {
    mt_srand(20261105);
    $operations = ['receive', 'issue', 'reserve', 'release', 'ship', 'deliver', 'failDelivery', 'issueReserved', 'hold', 'releaseHold', 'issueHeld'];

    for ($run = 0; $run < 1000; $run++) {
        $s = new StockBuckets;

        for ($step = 0; $step < 1000; $step++) {
            $op = $operations[mt_rand(0, count($operations) - 1)];
            $qty = mt_rand(1, 20);

            try {
                $s = $s->{$op}($qty);
            } catch (BusinessRuleViolation|LogicException) {
                // Refus attendu : l'état précédent est conservé intact.
            }

            expect($s->onHand)->toBe($s->available() + $s->reserved + $s->blocked + $s->inDelivery)
                ->and(min($s->onHand, $s->reserved, $s->blocked, $s->inDelivery, $s->available()))->toBeGreaterThanOrEqual(0);
        }
    }
});
