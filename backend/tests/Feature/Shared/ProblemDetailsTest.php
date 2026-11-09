<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Http\ProblemDetailsRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::get('business', fn () => throw new BusinessRuleViolation(
            'insufficient_stock', 'Stock insuffisant', 'Disponible : 5 pièces, demandé : 7 pièces.', 409, ['available' => 5, 'requested' => 7],
        ));
        Route::post('validation', fn (Request $r) => $r->validate(['lines' => 'required|array', 'lines.*.qty' => 'required|integer|min:1']));
        Route::get('crash', fn () => throw new RuntimeException('secret interne SQL'));
    });
});

it('rend une violation de règle métier en problem+json avec un code stable', function () {
    $response = $this->getJson('/api/v1/_test/business');

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'code' => 'insufficient_stock',
            'title' => 'Stock insuffisant',
            'status' => 409,
            'detail' => 'Disponible : 5 pièces, demandé : 7 pièces.',
            'meta' => ['available' => 5, 'requested' => 7],
            'instance' => '/api/v1/_test/business',
        ]);

    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('rend les erreurs de validation avec un pointeur JSON par champ', function () {
    $this->postJson('/api/v1/_test/validation', ['lines' => [['qty' => 0]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.pointer', '/lines/0/qty');
});

it('rend 404 not_found pour une route inexistante', function () {
    $this->getJson('/api/v1/_test/nexiste-pas')->assertStatus(404)->assertJsonPath('code', 'not_found');
});

it('ne divulgue aucun détail interne sur une erreur technique hors débogage', function () {
    config(['app.debug' => false]);
    $this->app->forgetInstance(ProblemDetailsRenderer::class);

    $response = $this->getJson('/api/v1/_test/crash');

    $response->assertStatus(500)->assertJsonPath('code', 'internal_error');
    expect($response->getContent())->not->toContain('secret interne');
});

it('réutilise un X-Request-Id valide fourni par le client', function () {
    $id = '01JC0000000000000000000000';

    $this->getJson('/api/v1/health', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);
});
