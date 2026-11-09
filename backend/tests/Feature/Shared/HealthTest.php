<?php

declare(strict_types=1);

use Tests\Support\OpenApiContract;

it('expose une santé publique minimale conforme au contrat', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()->assertExactJson(['status' => 'ok']);
    OpenApiContract::assertResponseMatches($response, 'GET', '/health');
});

it('fournit la commande app:health utilisée par Docker', function () {
    $this->artisan('app:health')->assertSuccessful();
});
