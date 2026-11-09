<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Services\FifoAllocator;
use App\Modules\Inventory\Domain\ValueObjects\LotCandidate;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/*
| INV-002 : premier entré, premier sorti (date de réception puis numéro de lot).
*/

function lots(): array
{
    return [
        new LotCandidate('C', 'LOT-2026-000003', '2026-03-01T00:00:00Z', 10),
        new LotCandidate('A', 'LOT-2026-000001', '2026-01-01T00:00:00Z', 10),
        new LotCandidate('B', 'LOT-2026-000002', '2026-01-01T00:00:00Z', 10),
    ];
}

it('sort 2,5 lots dans l\'ordre FIFO', function () {
    expect((new FifoAllocator)->allocate(25, lots()))->toBe(['A' => 10, 'B' => 10, 'C' => 5]);
});

it('ignore les lots sans quantité libre', function () {
    $candidates = [new LotCandidate('A', 'LOT-1', '2026-01-01T00:00:00Z', 0), new LotCandidate('B', 'LOT-2', '2026-02-01T00:00:00Z', 4)];

    expect((new FifoAllocator)->allocate(3, $candidates))->toBe(['B' => 3]);
});

it('refuse une sortie supérieure au stock libre des lots', function () {
    (new FifoAllocator)->allocate(31, lots());
})->throws(BusinessRuleViolation::class, 'Disponible : 30, demandé : 31.');

it('contrôle une affectation manuelle : quantité supérieure au lot refusée', function () {
    (new FifoAllocator)->validateManual(12, ['C' => 12], lots());
})->throws(BusinessRuleViolation::class, 'Disponible : 10, demandé : 12.');

it('accepte une affectation manuelle exacte, et refuse un lot inconnu', function () {
    $allocator = new FifoAllocator;

    expect($allocator->validateManual(12, ['C' => 7, 'A' => 5], lots()))->toBe(['C' => 7, 'A' => 5]);
    expect(fn () => $allocator->validateManual(5, ['Z' => 5], lots()))->toThrow(BusinessRuleViolation::class);
});
