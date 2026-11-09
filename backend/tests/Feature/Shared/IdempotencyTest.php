<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->executions = 0;

    Route::middleware(['api', 'idempotent'])->prefix('api/v1/_test')->group(function () {
        Route::post('validate', function () {
            $this->executions++;

            return response()->json(['data' => ['execution' => $this->executions]], 201, ['Location' => '/api/v1/_test/x']);
        });
        Route::post('fail', fn () => abort(503));
    });
});

function idemKey(): string
{
    return (string) Str::ulid();
}

it('TEST-008 rejoue la réponse initiale sans réexécuter l\'action', function () {
    $key = idemKey();

    $first = $this->postJson('/api/v1/_test/validate', ['id' => 1], ['Idempotency-Key' => $key]);
    $second = $this->postJson('/api/v1/_test/validate', ['id' => 1], ['Idempotency-Key' => $key]);

    $first->assertStatus(201);
    $second->assertStatus(201)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('Location', '/api/v1/_test/x')
        ->assertJsonPath('data.execution', 1);

    expect($this->executions)->toBe(1);
});

it('refuse la réutilisation d\'une clé pour une requête différente', function () {
    $key = idemKey();

    $this->postJson('/api/v1/_test/validate', ['id' => 1], ['Idempotency-Key' => $key])->assertStatus(201);
    $this->postJson('/api/v1/_test/validate', ['id' => 2], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');
});

it('exige l\'en-tête Idempotency-Key', function (?string $key) {
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    $this->postJson('/api/v1/_test/validate', [], $headers)
        ->assertStatus(428)
        ->assertJsonPath('code', 'idempotency_key_required');

    expect($this->executions)->toBe(0);
})->with([null, 'court', str_repeat('a', 65), 'clé avec espaces 1234']);

it('signale une requête identique encore en cours', function () {
    $key = idemKey();

    DB::table('idempotency_keys')->insert([
        'id' => idemKey(), 'owner' => 'anon:127.0.0.1', 'idempotency_key' => $key,
        'route' => 'POST api/v1/_test/validate',
        'request_hash' => hash('sha256', "POST\napi/v1/_test/validate\n".json_encode(['id' => 1])),
        'status' => 'processing', 'locked_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_test/validate', ['id' => 1], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertJsonPath('code', 'request_in_progress');
});

it('reprend une clé restée en cours après une interruption', function () {
    $key = idemKey();

    DB::table('idempotency_keys')->insert([
        'id' => idemKey(), 'owner' => 'anon:127.0.0.1', 'idempotency_key' => $key,
        'route' => 'POST api/v1/_test/validate',
        'request_hash' => hash('sha256', "POST\napi/v1/_test/validate\n".json_encode(['id' => 1])),
        'status' => 'processing', 'locked_at' => now()->subMinutes(5), 'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/_test/validate', ['id' => 1], ['Idempotency-Key' => $key])->assertStatus(201);
});

it('ne mémorise pas une erreur technique : la requête peut être rejouée', function () {
    $key = idemKey();

    $this->postJson('/api/v1/_test/fail', [], ['Idempotency-Key' => $key])->assertStatus(503);

    expect(DB::table('idempotency_keys')->where('idempotency_key', $key)->exists())->toBeFalse();
});
