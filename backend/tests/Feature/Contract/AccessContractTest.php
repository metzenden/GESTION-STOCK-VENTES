<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

/*
| DoD §3 : chaque opération du lot 1 renvoie une réponse conforme au contrat openapi/openapi.yaml.
*/

function contract(TestResponse $response, string $method, string $path, int $status): TestResponse
{
    $response->assertStatus($status);
    OpenApiContract::assertResponseMatches($response, $method, $path);

    return $response;
}

it('respecte le contrat : authentification et profil', function () {
    Mail::fake();
    $company = provisionedCompany();
    $user = userWithRole($company, RoleTemplate::Seller, ['email' => 'contrat@example.com']);

    contract($this->postJson('/api/v1/auth/login', ['email' => 'contrat@example.com', 'password' => UserFactory::PASSWORD]), 'POST', '/auth/login', 200);
    contract($this->postJson('/api/v1/auth/login', ['email' => 'contrat@example.com', 'password' => 'faux']), 'POST', '/auth/login', 422);
    contract(loginAs($user)->getJson('/api/v1/auth/me'), 'GET', '/auth/me', 200);
    contract(loginAs($user)->patchJson('/api/v1/auth/me/preferences', ['theme' => 'light']), 'PATCH', '/auth/me/preferences', 200);
    contract($this->postJson('/api/v1/auth/forgot-password', ['email' => 'contrat@example.com']), 'POST', '/auth/forgot-password', 202);
    contract($this->postJson('/api/v1/auth/reset-password', ['token' => 'x', 'email' => 'contrat@example.com', 'password' => 'MotDePasseValide-2026', 'password_confirmation' => 'MotDePasseValide-2026']), 'POST', '/auth/reset-password', 422);
    contract(loginAs($user)->postJson('/api/v1/auth/logout'), 'POST', '/auth/logout', 204);
    app('auth')->forgetGuards();
    contract($this->getJson('/api/v1/auth/me'), 'GET', '/auth/me', 401);
});

it('respecte le contrat : plateforme', function () {
    Mail::fake();
    $admin = superAdmin();

    $created = contract(loginAs($admin)->postJson('/api/v1/admin/companies', [
        'code' => 'CTR', 'trade_name' => 'Contrat SA', 'reference_currency' => 'XOF', 'admin_email' => 'gerant@contrat.example',
    ], ['Idempotency-Key' => Str::random(24)]), 'POST', '/admin/companies', 201);
    $id = $created->json('data.id');

    contract(loginAs($admin)->getJson('/api/v1/admin/companies'), 'GET', '/admin/companies', 200);
    contract(loginAs($admin)->getJson("/api/v1/admin/companies/{$id}"), 'GET', '/admin/companies/{id}', 200);
    contract(loginAs($admin)->patchJson("/api/v1/admin/companies/{$id}", ['city' => 'Thiès']), 'PATCH', '/admin/companies/{id}', 200);
    contract(loginAs($admin)->postJson("/api/v1/admin/companies/{$id}/invitations", ['email' => 'second@contrat.example']), 'POST', '/admin/companies/{id}/invitations', 204);
    contract(loginAs($admin)->postJson("/api/v1/admin/companies/{$id}/suspend", ['reason' => 'Contrôle']), 'POST', '/admin/companies/{id}/suspend', 200);
    contract(loginAs($admin)->postJson("/api/v1/admin/companies/{$id}/suspend", ['reason' => 'Contrôle']), 'POST', '/admin/companies/{id}/suspend', 409);
    contract(loginAs($admin)->postJson("/api/v1/admin/companies/{$id}/reactivate"), 'POST', '/admin/companies/{id}/reactivate', 200);
    contract(loginAs($admin)->postJson('/api/v1/context/company', ['company_id' => $id]), 'POST', '/context/company', 200);
    contract(loginAs($admin)->getJson('/api/v1/admin/audit-logs'), 'GET', '/admin/audit-logs', 200);
    contract(loginAs($admin)->getJson('/api/v1/admin/companies/'.str_repeat('0', 26)), 'GET', '/admin/companies/{id}', 404);
});

it('respecte le contrat : utilisateurs, invitations, rôles', function () {
    Mail::fake();
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $user = userWithRole($company, RoleTemplate::Seller);
    $seller = Role::query()->where('company_id', $company->id)->where('template_code', 'seller')->firstOrFail();

    contract(loginAs($admin)->getJson('/api/v1/users'), 'GET', '/users', 200);
    contract(loginAs($admin)->getJson("/api/v1/users/{$user->id}"), 'GET', '/users/{id}', 200);
    contract(loginAs($admin)->patchJson("/api/v1/users/{$user->id}", ['phone' => '+221 77 000 00 00']), 'PATCH', '/users/{id}', 200);
    contract(loginAs($admin)->postJson("/api/v1/users/{$user->id}/disable"), 'POST', '/users/{id}/disable', 200);
    contract(loginAs($admin)->postJson("/api/v1/users/{$user->id}/enable"), 'POST', '/users/{id}/enable', 200);

    $invitation = contract(loginAs($admin)->postJson('/api/v1/invitations', ['email' => 'nouveau@example.com', 'role_id' => $seller->id]), 'POST', '/invitations', 201);
    contract(loginAs($admin)->getJson('/api/v1/invitations'), 'GET', '/invitations', 200);
    contract(loginAs($admin)->postJson('/api/v1/invitations/'.$invitation->json('data.id').'/revoke'), 'POST', '/invitations/{id}/revoke', 200);

    $role = contract(loginAs($admin)->postJson('/api/v1/roles', ['name' => 'Caissier', 'permissions' => ['invoices.view']]), 'POST', '/roles', 201);
    contract(loginAs($admin)->getJson('/api/v1/roles'), 'GET', '/roles', 200);
    contract(loginAs($admin)->getJson('/api/v1/roles/'.$role->json('data.id')), 'GET', '/roles/{id}', 200);
    contract(loginAs($admin)->patchJson('/api/v1/roles/'.$role->json('data.id'), ['name' => 'Caisse']), 'PATCH', '/roles/{id}', 200);
    contract(loginAs($admin)->deleteJson('/api/v1/roles/'.$role->json('data.id')), 'DELETE', '/roles/{id}', 204);
    contract(loginAs($admin)->getJson('/api/v1/permissions'), 'GET', '/permissions', 200);
    contract(loginAs($user)->getJson('/api/v1/users'), 'GET', '/users', 403);
});

it('respecte le contrat : paramètres et audit', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);

    contract(loginAs($admin)->getJson('/api/v1/settings/company'), 'GET', '/settings/company', 200);
    contract(loginAs($admin)->patchJson('/api/v1/settings/company', ['settings' => ['start_date' => '2026-12-01']]), 'PATCH', '/settings/company', 200);
    contract(loginAs($admin)->getJson('/api/v1/settings/document-sequences'), 'GET', '/settings/document-sequences', 200);
    contract(loginAs($admin)->patchJson('/api/v1/settings/document-sequences', ['formats' => [['doc_type' => 'quotation', 'prefix' => 'PRO', 'padding' => 4]]]), 'PATCH', '/settings/document-sequences', 200);
    contract(loginAs($admin)->postJson('/api/v1/exchange-rates', ['from_currency' => 'CNY', 'to_currency' => 'XOF', 'rate' => '84.5', 'valid_on' => '2026-11-05']), 'POST', '/exchange-rates', 201);
    contract(loginAs($admin)->getJson('/api/v1/exchange-rates'), 'GET', '/exchange-rates', 200);
    $tax = contract(loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '2.5']), 'POST', '/tax-rates', 201);
    contract(loginAs($admin)->getJson('/api/v1/tax-rates'), 'GET', '/tax-rates', 200);
    contract(loginAs($admin)->patchJson('/api/v1/tax-rates/'.$tax->json('data.id'), ['is_active' => false]), 'PATCH', '/tax-rates/{id}', 200);
    contract(loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => '', 'rate' => 'abc']), 'POST', '/tax-rates', 422);
    contract(loginAs($admin)->getJson('/api/v1/audit-logs'), 'GET', '/audit-logs', 200);

    $company->forceFill(['status' => 'suspended'])->save();
    contract(loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Autre', 'rate' => '1']), 'POST', '/tax-rates', 423);
});
