<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObjects\Currency;
use App\Modules\Shared\Domain\ValueObjects\Money;

$xof = fn () => Currency::of('XOF', 0);
$eur = fn () => Currency::of('EUR', 2);

it('D-013 arrondit au franc supérieur à partir de ,5 (HALF_UP)', function (string $amount, string $expected) use ($xof) {
    expect(Money::of($amount, $xof())->rounded()->toDecimalString())->toBe($expected);
})->with([
    ['1250.5', '1251'],
    ['1250.4999', '1250'],
    ['1250.5000', '1251'],
    ['-1250.5', '-1251'],
    ['0.5', '1'],
    ['99999.49', '99999'],
]);

it('arrondit à la précision de la devise (EUR : 2 décimales)', function () use ($eur) {
    expect(Money::of('10.005', $eur())->rounded()->toDecimalString())->toBe('10.01')
        ->and(Money::of('10.004', $eur())->rounded()->toDecimalString())->toBe('10.00');
});

it('additionne et soustrait sans perte de précision', function () use ($xof) {
    $total = Money::of('0.1', $xof())->plus(Money::of('0.2', $xof()));

    expect($total->amount()->isEqualTo('0.3'))->toBeTrue()
        ->and(Money::of('100000', $xof())->minus(Money::of('40000', $xof()))->toDecimalString())->toBe('60000');
});

it('refuse les opérations entre devises différentes sans conversion', function () use ($xof, $eur) {
    Money::of('100', $xof())->plus(Money::of('1', $eur()));
})->throws(InvalidArgumentException::class);

it('refuse les entrées non décimales (flottants en chaîne mal formés, notation scientifique)', function (string $input) use ($xof) {
    Money::of($input, $xof());
})->with(['1e3', '12,5', 'abc', '', '1.2.3'])->throws(InvalidArgumentException::class);

it('stocke en DECIMAL(19,4) et refuse une perte de précision implicite', function () use ($xof) {
    expect(Money::of('12.5', $xof())->toStorage())->toBe('12.5000');

    Money::of('1.123456', $xof())->toStorage();
})->throws(InvalidArgumentException::class);

it('sérialise en JSON avec un montant en chaîne', function () use ($xof) {
    expect(json_encode(Money::of(100000, $xof())))->toBe('{"amount":"100000","currency":"XOF"}');
});

it('conserve les décimales d\'un montant non arrondi (coût unitaire)', function () use ($xof) {
    expect(Money::of('33333.33333333', $xof())->toDecimalString())->toBe('33333.33333333');
});
