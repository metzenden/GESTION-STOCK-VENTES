<?php

declare(strict_types=1);

use App\Modules\Costing\Domain\Expressions\Node;
use App\Modules\Costing\Domain\Expressions\Parser;
use App\Modules\Costing\Domain\Services\LandedCostCalculator;
use Brick\Math\BigDecimal;

/*
| L3-12 : formule standard (docs/04 §7.1), TEST-009 et réconciliation au plus fort reste (CR-008).
*/

function standardFormula(): Node
{
    return (new Parser(LandedCostCalculator::VARIABLES))->parse(LandedCostCalculator::STANDARD_EXPRESSION);
}

function costLine(int $qty, string $cbm, string $foreign, string $rate = '1', ?int $ppc = null): array
{
    return ['qty' => $qty, 'cbm' => $cbm, 'purchase_foreign' => $foreign, 'rate' => $rate, 'pieces_per_carton' => $ppc];
}

it('TEST-009 : 500 000 de frais communs sur 2 m³ et 3 m³ donnent 200 000 et 300 000', function () {
    $result = (new LandedCostCalculator)->compute(
        ['A' => costLine(100, '2', '1000', '655.957', 10), 'B' => costLine(300, '3', '500', '655.957')],
        ['F' => ['amount' => '500000', 'method' => 'cbm', 'target' => null]],
        standardFormula(),
    );

    expect((string) $result['lines']['A']['common_fees'])->toBe('200000.0000')
        ->and((string) $result['lines']['B']['common_fees'])->toBe('300000.0000')
        ->and((string) $result['lines']['A']['purchase_converted'])->toBe('655957.00000000')
        ->and((string) $result['lines']['A']['total_cost'])->toBe('855957.00000000')
        ->and((string) $result['lines']['A']['unit_cost_piece'])->toBe('8559.57000000')
        ->and((string) $result['lines']['A']['unit_cost_carton'])->toBe('85595.70000000')
        ->and($result['lines']['B']['unit_cost_carton'])->toBeNull()
        ->and($result['total_common'])->toBe('500000.0000')
        ->and($result['total_cost'])->toBe('1483935.5000');
});

it('CR-008 : 100 000 sur trois lignes égales — le reste va à la première, la somme est exacte', function () {
    $result = (new LandedCostCalculator)->compute(
        ['L1' => costLine(1, '1', '0'), 'L2' => costLine(1, '1', '0'), 'L3' => costLine(1, '1', '0')],
        ['F' => ['amount' => '100000', 'method' => 'cbm', 'target' => null]],
        standardFormula(),
    );

    $shares = array_map(fn ($l) => (string) $l['common_fees'], $result['lines']);
    $sum = array_reduce($shares, fn (BigDecimal $c, string $s) => $c->plus($s), BigDecimal::zero());

    expect($shares)->toBe(['L1' => '33333.3334', 'L2' => '33333.3333', 'L3' => '33333.3333'])
        ->and((string) $sum)->toBe('100000.0000')
        ->and((string) $result['lines']['L1']['rounding_adjustment'])->toBe('0.00006667');
});

it('répartit chaque frais une seule fois et affecte les frais directs à leur ligne (CR-002)', function () {
    $result = (new LandedCostCalculator)->compute(
        ['A' => costLine(10, '1', '100'), 'B' => costLine(10, '1', '100')],
        [
            'F1' => ['amount' => '1000', 'method' => 'cbm', 'target' => null],
            'F2' => ['amount' => '333', 'method' => 'cbm', 'target' => null],
            'D' => ['amount' => '50', 'method' => 'direct', 'target' => 'B'],
        ],
        standardFormula(),
    );

    expect(array_map('strval', $result['lines']['A']['allocations']))->toBe(['F1' => '500.0000', 'F2' => '166.5000'])
        ->and(array_map('strval', $result['lines']['B']['allocations']))->toBe(['F1' => '500.0000', 'F2' => '166.5000', 'D' => '50.0000'])
        ->and($result['total_direct'])->toBe('50.0000')
        ->and($result['total_cost'])->toBe('1583.0000');
});

it('est reproductible : mêmes entrées, mêmes résultats', function () {
    $lines = ['A' => costLine(7, '1.234567', '1234.5678', '600.5'), 'B' => costLine(13, '0.5', '99.99', '600.5', 6)];
    $fees = ['F' => ['amount' => '987654.3210', 'method' => 'cbm', 'target' => null]];

    expect((new LandedCostCalculator)->compute($lines, $fees, standardFormula()))
        ->toEqual((new LandedCostCalculator)->compute($lines, $fees, standardFormula()));
});
