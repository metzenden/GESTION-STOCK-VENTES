<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Tenancy\Infrastructure\Http\Middleware\ResolveCompanyContext;
use Illuminate\Support\Facades\DB;

/*
| TEST-002, MT-008, L1-11 : bascule d'entreprise du super-admin, auditée ; refus pour un utilisateur ordinaire.
*/

it('bascule de A vers B : les données affichées sont celles de l\'entreprise choisie, et la bascule est auditée', function () {
    $a = provisionedCompany(['trade_name' => 'Entreprise A']);
    $b = provisionedCompany(['trade_name' => 'Entreprise B']);
    userWithRole($a, attributes: ['name' => 'Utilisateur de A']);
    userWithRole($b, attributes: ['name' => 'Utilisateur de B']);
    $admin = superAdmin();

    loginAs($admin)->getJson('/api/v1/users')->assertStatus(409)->assertJsonPath('code', 'company_context_required');

    loginAs($admin)->postJson('/api/v1/context/company', ['company_id' => $a->id])
        ->assertOk()->assertJsonPath('data.company.trade_name', 'Entreprise A');
    $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('data.0.name', 'Utilisateur de A')->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/context/company', ['company_id' => $b->id])->assertOk();
    $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('data.0.name', 'Utilisateur de B')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.company.id', $b->id);

    $switches = DB::table('audit_logs')->where(['action' => 'context.company_switched', 'user_id' => $admin->id])->get();
    expect($switches)->toHaveCount(2)
        ->and(json_decode($switches->last()->new_values, true))->toBe(['company_id' => $b->id]);
});

it('refuse une entreprise inexistante', function () {
    loginAs(superAdmin())->postJson('/api/v1/context/company', ['company_id' => str_repeat('0', 26)])->assertNotFound();
});

it('interdit la bascule et les routes de plateforme à un utilisateur ordinaire', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    $admin = userWithRole($a, RoleTemplate::CompanyAdmin);

    loginAs($admin)->postJson('/api/v1/context/company', ['company_id' => $b->id])->assertForbidden();
    loginAs($admin)->getJson('/api/v1/admin/companies')->assertForbidden();
    loginAs($admin)->getJson("/api/v1/admin/companies/{$b->id}")->assertForbidden();
    loginAs($admin)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});

it('ignore une entreprise choisie en session par un utilisateur ordinaire', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    $user = userWithRole($a);

    loginAs($user, [ResolveCompanyContext::SESSION_KEY => $b->id])->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.company.id', $a->id);
});

it('ignore un company_id fourni par le client (MT-007)', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    $user = userWithRole($a);

    loginAs($user)->postJson('/api/v1/tax-rates?company_id='.$b->id, ['name' => 'Taxe', 'rate' => '5', 'company_id' => $b->id])
        ->assertCreated();

    expect(DB::table('tax_rates')->where('name', 'Taxe')->value('company_id'))->toBe($a->id);
});
