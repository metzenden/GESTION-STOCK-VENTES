<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Mail\SuperAdminLoginAlert;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| L1-04, L1-05 : connexion par session, réponses indistinctes, limitation de débit, alerte super-admin.
*/

it('connecte un utilisateur actif et renvoie son profil, son entreprise et ses permissions', function () {
    $company = provisionedCompany(['trade_name' => 'FDS Dakar']);
    $user = userWithRole($company, RoleTemplate::Seller, ['email' => 'vendeur@example.com']);

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'Vendeur@Example.com ', 'password' => UserFactory::PASSWORD]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.company.trade_name', 'FDS Dakar')
        ->assertJsonPath('data.user.is_super_admin', false);
    expect($response->json('data.permissions'))->toContain('sales.create')->not->toContain('reports.financial');

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', 'vendeur@example.com');
    expect(DB::table('audit_logs')->where(['action' => 'auth.login', 'user_id' => $user->id])->exists())->toBeTrue();
});

it('répond de la même façon pour un e-mail inconnu, un mauvais mot de passe ou un compte désactivé', function () {
    $company = provisionedCompany();
    userWithRole($company, attributes: ['email' => 'actif@example.com']);
    User::factory()->forCompany($company)->disabled()->create(['email' => 'inactif@example.com']);

    $responses = [
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.com', 'password' => UserFactory::PASSWORD]),
        $this->postJson('/api/v1/auth/login', ['email' => 'actif@example.com', 'password' => 'mauvais-mot-de-passe']),
        $this->postJson('/api/v1/auth/login', ['email' => 'inactif@example.com', 'password' => UserFactory::PASSWORD]),
    ];

    foreach ($responses as $response) {
        $response->assertStatus(422)->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonPath('detail', 'Adresse e-mail ou mot de passe incorrect.');
    }

    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('bloque la 6e tentative en 15 minutes pour un même e-mail et une même adresse IP', function () {
    $company = provisionedCompany();
    userWithRole($company, attributes: ['email' => 'cible@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'cible@example.com', 'password' => 'faux'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'cible@example.com', 'password' => UserFactory::PASSWORD])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('alerte le super-administrateur par e-mail à chaque connexion', function () {
    Mail::fake();
    $admin = superAdmin();

    $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => UserFactory::PASSWORD])
        ->assertOk()->assertJsonPath('data.company', null);

    Mail::assertQueued(SuperAdminLoginAlert::class, fn ($mail) => $mail->hasTo($admin->email));
});

it('limite plus strictement les essais sur un compte super-administrateur', function () {
    $admin = superAdmin();

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'faux'], ['REMOTE_ADDR' => "10.0.0.{$attempt}"])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => UserFactory::PASSWORD], ['REMOTE_ADDR' => '10.0.0.9'])
        ->assertStatus(429);
});

it('déconnecte et ferme la session', function () {
    $company = provisionedCompany();
    $user = userWithRole($company);

    loginAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(auth('web')->check())->toBeFalse();
});

it('enregistre la préférence de thème', function () {
    $company = provisionedCompany();
    $user = userWithRole($company);

    loginAs($user)->patchJson('/api/v1/auth/me/preferences', ['theme' => 'dark'])
        ->assertOk()->assertJsonPath('data.user.theme', 'dark');

    loginAs($user)->patchJson('/api/v1/auth/me/preferences', ['theme' => 'rose'])->assertStatus(422);
});

it('fixe la durée de session à 240 minutes d\'inactivité (CDC §4.1)', function () {
    expect((int) config('session.lifetime'))->toBe(240)
        ->and(config('session.expire_on_close'))->toBeFalse();
});

it('révoque immédiatement la session d\'un utilisateur désactivé (ADM-003)', function () {
    $company = provisionedCompany();
    $user = userWithRole($company, RoleTemplate::Seller);
    $epoch = $user->session_epoch;

    loginAs($user)->getJson('/api/v1/auth/me')->assertOk();

    $user->forceFill(['status' => 'disabled', 'session_epoch' => $user->session_epoch + 1])->save();

    loginAs($user->refresh(), epoch: $epoch)->getJson('/api/v1/auth/me')
        ->assertStatus(401)->assertJsonPath('code', 'session_expired');
});
