<?php

declare(strict_types=1);

use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsConcurrently;

it('TEST-021 attribue 50 numéros distincts et continus à des factures émises simultanément', function () {
    $company = Company::factory()->create();

    // 5 processus × 10 factures, séquence inexistante au départ (cas le plus exposé aux interblocages).
    $results = RunsConcurrently::run('allocate-numbers', 5, [
        'company_id' => $company->id, 'type' => 'invoice', 'date' => '2026-11-05', 'count' => 10,
    ]);

    $numbers = array_merge(...$results);
    sort($numbers);

    $expected = array_map(fn (int $n) => sprintf('FAC-2026-%04d', $n), range(1, 50));

    expect($numbers)->toBe($expected)
        ->and((int) DB::table('document_sequences')->where('company_id', $company->id)->value('next_number'))->toBe(51);
});
