<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\Services\LargestRemainderAllocator;
use Brick\Math\BigDecimal;

$allocator = fn () => new LargestRemainderAllocator;

it('TEST-009 répartit 500 000 FCFA au prorata de 2 m³ et 3 m³', function () use ($allocator) {
    $shares = $allocator()->allocate('500000', ['A' => '2', 'B' => '3'], 4);

    expect((string) $shares['A'])->toBe('200000.0000')
        ->and((string) $shares['B'])->toBe('300000.0000');
});

it('CR-008 attribue le reste au plus fort reste pour que la somme soit exacte', function () use ($allocator) {
    $shares = $allocator()->allocate('100000', ['L1' => '1', 'L2' => '1', 'L3' => '1'], 4);

    expect((string) $shares['L1'])->toBe('33333.3334')
        ->and((string) $shares['L2'])->toBe('33333.3333')
        ->and((string) $shares['L3'])->toBe('33333.3333');
});

it('CR-001 refuse une base de répartition nulle', function () use ($allocator) {
    $allocator()->allocate('500000', ['A' => '0', 'B' => '0'], 4);
})->throws(BusinessRuleViolation::class);

it('refuse un poids négatif', function () use ($allocator) {
    $allocator()->allocate('100', ['A' => '-1', 'B' => '2'], 0);
})->throws(InvalidArgumentException::class);

it('attribue zéro aux éléments de poids nul', function () use ($allocator) {
    $shares = $allocator()->allocate('10', ['A' => '0', 'B' => '3', 'C' => '0'], 0);

    expect((string) $shares['A'])->toBe('0')->and((string) $shares['B'])->toBe('10')->and((string) $shares['C'])->toBe('0');
});

it('répartit un montant négatif (contre-écriture) symétriquement', function () use ($allocator) {
    $shares = $allocator()->allocate('-100', ['A' => '1', 'B' => '2'], 0);

    expect((string) $shares['A'])->toBe('-33')->and((string) $shares['B'])->toBe('-67');
});

it('propriété : la somme des parts égale toujours le montant (2 000 jeux aléatoires)', function () use ($allocator) {
    mt_srand(20261105);

    for ($run = 0; $run < 2000; $run++) {
        $scale = mt_rand(0, 4);
        $total = BigDecimal::ofUnscaledValue(mt_rand(0, 10_000_000_000), $scale);
        $weights = [];

        for ($i = 0, $n = mt_rand(1, 12); $i < $n; $i++) {
            $weights["l{$i}"] = (string) BigDecimal::ofUnscaledValue(mt_rand(0, 5_000_000), 6);
        }

        $weightSum = array_reduce($weights, fn (BigDecimal $carry, string $w) => $carry->plus($w), BigDecimal::zero());

        if ($weightSum->isZero()) {
            $weights['l0'] = '1';
        }

        $shares = $allocator()->allocate($total, $weights, $scale);
        $sum = array_reduce($shares, fn (BigDecimal $carry, BigDecimal $s) => $carry->plus($s), BigDecimal::zero());

        expect($sum->isEqualTo($total))->toBeTrue("Écart sur le jeu {$run}");

        foreach ($shares as $share) {
            expect($share->isNegative())->toBeFalse();
        }
    }
});
