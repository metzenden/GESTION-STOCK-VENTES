<?php

declare(strict_types=1);

use App\Modules\Imports\Domain\CellValueParser;

it('lit les nombres aux formats français et anglo-saxons sans approximation', function (?string $input, ?string $expected) {
    expect(CellValueParser::decimal($input)[0])->toBe($expected);
})->with([
    ['1 250,50', '1250.5'],
    ['1,250.50', '1250.5'],
    ['1.250,50', '1250.5'],
    ['0,125', '0.125'],
    ['12', '12'],
    ['1,000,000', '1000000'],
    ['  ', null],
    [null, null],
    ['$ 3.20', '3.2'],
]);

it('refuse les valeurs illisibles ou trop précises', function () {
    expect(CellValueParser::decimal('abc')[1])->toBe('Nombre illisible.')
        ->and(CellValueParser::decimal('0.123456789')[1])->toBe('Plus de 8 décimales.')
        ->and(CellValueParser::integer('2,5')[1])->toBe('Un nombre entier est attendu.')
        ->and(CellValueParser::integer('1 200')[0])->toBe(1200);
});

it('normalise les en-têtes pour la reconnaissance automatique', function () {
    expect(CellValueParser::normalizeHeader('Pcs / Ctn'))->toBe('pcsctn')
        ->and(CellValueParser::normalizeHeader('Désignation'))->toBe('designation');
});
