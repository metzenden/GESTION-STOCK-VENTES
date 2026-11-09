<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Mail\InvitationMail;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| ADM-002, L1-10 : invitation à usage unique, valable 72 h, révocable ; compte rattaché à l'entreprise.
*/

function inviteAs(User $admin, string $email, RoleTemplate $template = RoleTemplate::Seller): string
{
    $role = Role::query()->where('company_id', $admin->company_id)->where('template_code', $template->value)->firstOrFail();

    loginAs($admin)->postJson('/api/v1/invitations', ['email' => $email, 'role_id' => $role->id])->assertCreated();

    $token = null;
    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($email, &$token) {
        if ($mail->hasTo($email)) {
            $token = $mail->token;
        }

        return true;
    });

    return (string) $token;
}

function acceptPayload(string $token): array
{
    return ['token' => $token, 'name' => 'Awa Diop', 'password' => 'MotDePasse-Awa-2026', 'password_confirmation' => 'MotDePasse-Awa-2026'];
}

it('invite un utilisateur, qui active son compte dans l\'entreprise avec le rôle prévu', function () {
    Mail::fake();
    $company = provisionedCompany();
    $admin = userWithRole($company);

    $token = inviteAs($admin, 'awa@example.com');
    expect(DB::table('invitations')->where('email', 'awa@example.com')->value('token_hash'))->toBe(hash('sha256', $token));

    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($token))
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'awa@example.com')
        ->assertJsonPath('data.company.id', $company->id);

    $user = User::query()->where('email', 'awa@example.com')->firstOrFail();
    actingAsCompany($company);
    expect($user->company_id)->toBe($company->id)
        ->and($user->hasRole(Role::query()->where('company_id', $company->id)->where('template_code', 'seller')->first()))->toBeTrue();

    // Connecté directement après l'activation.
    $this->assertAuthenticatedAs($user, 'web');
});

it('refuse un jeton déjà utilisé', function () {
    Mail::fake();
    $admin = userWithRole(provisionedCompany());
    $token = inviteAs($admin, 'awa@example.com');

    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($token))->assertCreated();
    auth('web')->logout();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($token))
        ->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
});

it('refuse une invitation expirée après 72 heures', function () {
    Mail::fake();
    $admin = userWithRole(provisionedCompany());
    $token = inviteAs($admin, 'awa@example.com');

    $this->travel(73)->hours();

    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($token))
        ->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
});

it('refuse une invitation révoquée, et une nouvelle invitation révoque la précédente', function () {
    Mail::fake();
    $admin = userWithRole(provisionedCompany());
    $first = inviteAs($admin, 'awa@example.com');
    $second = inviteAs($admin, 'awa@example.com');

    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($first))->assertStatus(422);

    $id = DB::table('invitations')->where('token_hash', hash('sha256', $second))->value('id');
    loginAs($admin)->postJson("/api/v1/invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');

    auth('web')->logout();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/accept-invitation', acceptPayload($second))->assertStatus(422);
});

it('refuse d\'inviter une adresse déjà utilisée par un compte, même d\'une autre entreprise', function () {
    Mail::fake();
    $admin = userWithRole(provisionedCompany());
    userWithRole(provisionedCompany(), attributes: ['email' => 'pris@example.com']);
    $role = Role::query()->where('company_id', $admin->company_id)->where('template_code', 'seller')->firstOrFail();

    loginAs($admin)->postJson('/api/v1/invitations', ['email' => 'pris@example.com', 'role_id' => $role->id])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/email');
});

it('refuse d\'inviter avec un rôle d\'une autre entreprise', function () {
    Mail::fake();
    $admin = userWithRole(provisionedCompany());
    $foreignRole = Role::query()->where('company_id', provisionedCompany()->id)->firstOrFail();

    loginAs($admin)->postJson('/api/v1/invitations', ['email' => 'x@example.com', 'role_id' => $foreignRole->id])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/role_id');
});
