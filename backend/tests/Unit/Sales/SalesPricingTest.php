<?php

declare(strict_types=1);

use App\Modules\Sales\Domain\Services\SalesPricing;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/*
| SAL-007, BIL-005, D-013 : remises par ligne et globale, taxe par ligne, arrondi à la devise.
*/

function priceLine(int $qty, string $price, ?string $type = null, ?string $value = null, string $tax = '0'): array
{
    return ['qty_units' => $qty, 'unit_price' => $price, 'discount_type' => $type, 'discount_value' => $value, 'tax_rate' => $tax];
}

it('applique remises de ligne, remise globale au prorata et taxe arrondie (XOF)', function () {
    $result = (new SalesPricing)->compute([
        priceLine(3, '2500', 'percent', '10'),       // brut 7 500, remise 750 → net 6 750
        priceLine(1, '1001', 'amount', '1'),         // brut 1 001, remise 1 → net 1 000
    ], ['type' => 'amount', 'value' => '1000'], 0);  // 1 000 répartis 6 750 / 1 000 → 871 / 129

    expect($result['lines'][0])->toMatchArray(['gross' => '7500', 'discount_amount' => '1621', 'net' => '5879', 'line_total' => '5879'])
        ->and($result['lines'][1])->toMatchArray(['discount_amount' => '130', 'net' => '871'])
        ->and($result['subtotal'])->toBe('8501')
        ->and($result['discount_total'])->toBe('1751')
        ->and($result['total'])->toBe('6750');
});

it('calcule la taxe par ligne sur le net, sans taux par défaut (BIL-005)', function () {
    $result = (new SalesPricing)->compute([priceLine(1, '1005', null, null, '18'), priceLine(2, '10.25', null, null, '0')], null, 0);

    // 1 005 × 18 % = 180,9 → 181 ; prix 10,25 × 2 = 20,5 → 21 (HALF_UP).
    expect($result['lines'][0]['tax_amount'])->toBe('181')
        ->and($result['lines'][1])->toMatchArray(['gross' => '21', 'tax_amount' => '0'])
        ->and($result['tax_total'])->toBe('181')
        ->and($result['total'])->toBe('1207');
});

it('respecte la précision de la devise (EUR, 2 décimales)', function () {
    $result = (new SalesPricing)->compute([priceLine(3, '0.3333', 'percent', '5', '20')], null, 2);

    expect($result['lines'][0])->toMatchArray(['gross' => '1.00', 'discount_amount' => '0.05', 'net' => '0.95', 'tax_amount' => '0.19', 'line_total' => '1.14']);
});

it('refuse une remise négative, supérieure à 100 % ou au montant de la ligne', function (array $line) {
    expect(fn () => (new SalesPricing)->compute([$line], null, 0))->toThrow(BusinessRuleViolation::class);
})->with([
    'négative' => [priceLine(1, '100', 'amount', '-1')],
    'plus de 100 %' => [priceLine(1, '100', 'percent', '101')],
    'supérieure au brut' => [priceLine(1, '100', 'amount', '150')],
]);
