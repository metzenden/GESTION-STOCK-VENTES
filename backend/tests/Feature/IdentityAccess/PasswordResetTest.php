<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Mail;

/*
| L1-06 : réinitialisation du mot de passe — réponse constante, jeton à usage unique, sessions fermées.
*/

it('répond de la même façon que l\'adresse existe ou non, et n\'envoie qu\'aux comptes actifs', function () {
    Mail::fake();
    $company = provisionedCompany();
    userWithRole($company, attributes: ['email' => 'connu@example.com']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'connu@example.com']);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.com']);

    $known->assertStatus(202);
    expect($unknown->status())->toBe(202)->and($unknown->json())->toBe($known->json());
    Mail::assertQueued(PasswordResetMail::class, 1);
});

it('réinitialise avec un jeton valide, une seule fois, et ferme les autres sessions', function () {
    Mail::fake();
    $company = provisionedCompany();
    $user = userWithRole($company, RoleTemplate::Seller, ['email' => 'oubli@example.com']);
    $oldEpoch = $user->session_epoch;

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'oubli@example.com']);

    $token = null;
    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    $payload = ['token' => $token, 'email' => 'oubli@example.com', 'password' => 'NouveauMotDePasse-2026', 'password_confirmation' => 'NouveauMotDePasse-2026'];

    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

    expect($user->refresh()->session_epoch)->toBe($oldEpoch + 1);

    loginAs($user->refresh(), epoch: $oldEpoch)->getJson('/api/v1/auth/me')->assertStatus(401);

    $this->postJson('/api/v1/auth/login', ['email' => 'oubli@example.com', 'password' => 'NouveauMotDePasse-2026'])->assertOk();
});

it('refuse un jeton expiré (plus de 60 minutes)', function () {
    Mail::fake();
    $company = provisionedCompany();
    userWithRole($company, attributes: ['email' => 'tard@example.com']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'tard@example.com']);

    $token = null;
    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'tard@example.com',
        'password' => 'NouveauMotDePasse-2026', 'password_confirmation' => 'NouveauMotDePasse-2026',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
});

it('exige un mot de passe d\'au moins 12 caractères', function () {
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'x', 'email' => 'a@example.com', 'password' => 'court1', 'password_confirmation' => 'court1',
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
