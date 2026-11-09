<?php

declare(strict_types=1);

use App\Modules\Costing\Domain\Expressions\Evaluator;
use App\Modules\Costing\Domain\Expressions\ExpressionError;
use App\Modules\Costing\Domain\Expressions\Parser;
use App\Modules\Costing\Domain\Services\LandedCostCalculator;
use Brick\Math\BigDecimal;

/*
| L3-11 / CR-014 : moteur d'expressions restreint — exact, sans exécution arbitraire.
*/

function evaluateFormula(string $expression, array $variables = []): string
{
    $node = (new Parser(LandedCostCalculator::VARIABLES))->parse($expression);

    return (string) (new Evaluator)->evaluate($node, array_map(fn ($v) => BigDecimal::of($v), $variables));
}

it('évalue exactement, avec priorités, parenthèses et fonctions autorisées', function () {
    expect(evaluateFormula('purchase_converted + common_fees + direct_fees', ['purchase_converted' => '655957', 'common_fees' => '200000.0001', 'direct_fees' => '15000']))
        ->toBe('870957.0001')
        ->and(evaluateFormula('2 + 3 * 4'))->toBe('14')
        ->and(evaluateFormula('(2 + 3) * 4'))->toBe('20')
        ->and(evaluateFormula('-qty + 10', ['qty' => '4']))->toBe('6')
        ->and(evaluateFormula('0.1 + 0.2'))->toBe('0.3')
        ->and(evaluateFormula('1 / 3'))->toBe('0.333333333333')
        ->and(evaluateFormula('round(10 / 3, 2)'))->toBe('3.33')
        ->and(evaluateFormula('max(qty, 5) + min(1, 2, 3)', ['qty' => '7']))->toBe('8');
});

it('refuse toute construction hors liste blanche', function (string $expression) {
    expect(fn () => (new Parser(LandedCostCalculator::VARIABLES))->parse($expression))->toThrow(ExpressionError::class);
})->with([
    'variable inconnue' => 'secret_cost + 1',
    'fonction non autorisée' => 'system(1)',
    'appel PHP' => 'exec("ls")',
    'accès tableau' => 'qty[0]',
    'opérateur exotique' => 'qty ** 2',
    'chaîne' => "'abc'",
    'point-virgule' => 'qty; 1',
    'variable PHP' => '$qty',
    'parenthèse non fermée' => '(qty + 1',
    'vide' => '   ',
    'round sans décimales littérales' => 'round(qty, qty)',
    'round trop précis' => 'round(qty, 9)',
    'trop imbriqué' => str_repeat('(', 40).'1'.str_repeat(')', 40),
    'trop long' => str_repeat('1+', 1200).'1',
]);

it('signale la division par zéro sans planter', function () {
    expect(fn () => evaluateFormula('qty / (cbm - cbm)', ['qty' => '1', 'cbm' => '2']))->toThrow(ExpressionError::class, 'Division par zéro.');
});

it('résiste au fuzzing : seule une ExpressionError peut sortir', function () {
    $alphabet = str_split('0123456789.+-*/(), qtycbmrounminax_$;"\'[]{}#!%^&|<>=?:@`~\\');
    mt_srand(20261106);

    for ($i = 0; $i < 3000; $i++) {
        $expression = '';
        $length = mt_rand(1, 40);

        for ($j = 0; $j < $length; $j++) {
            $expression .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        try {
            evaluateFormula($expression, array_fill_keys(LandedCostCalculator::VARIABLES, '3'));
        } catch (ExpressionError) {
            // attendu
        }
    }

    expect(true)->toBeTrue();
});

it('liste les variables utilisées par une formule', function () {
    $node = (new Parser(LandedCostCalculator::VARIABLES))->parse('purchase_converted * 1.02 + max(common_fees, direct_fees) + common_fees');

    expect($node->variables())->toEqualCanonicalizing(['purchase_converted', 'common_fees', 'direct_fees']);
});
