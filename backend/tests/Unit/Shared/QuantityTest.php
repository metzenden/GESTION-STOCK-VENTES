<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObjects\Quantity;

it('TEST-022 convertit 2 cartons de 6 et 3 pièces en 15 pièces', function () {
    $total = Quantity::fromUnits(2, 6)->plus(Quantity::fromUnits(3, 1));

    expect($total->value)->toBe(15);
});

it('refuse un facteur de conditionnement nul', function () {
    Quantity::fromUnits(3, 0);
})->throws(InvalidArgumentException::class);

it('détecte un dépassement de capacité', function () {
    Quantity::fromUnits(PHP_INT_MAX, 2);
})->throws(OverflowException::class);

it('gère les quantités signées des mouvements', function () {
    expect(Quantity::of(8)->negated()->isNegative())->toBeTrue()
        ->and(Quantity::of(20)->minus(Quantity::of(8))->value)->toBe(12);
});
