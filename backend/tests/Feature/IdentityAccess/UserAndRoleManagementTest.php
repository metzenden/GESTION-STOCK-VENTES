<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\PermissionCatalog;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use Illuminate\Support\Facades\DB;

/*
| L1-07, L1-13 : utilisateurs et rôles de l'entreprise ; ADM-003, ADM-004, ADM-005 ; matrice des rôles par défaut.
*/

function roleOf(string $companyId, RoleTemplate $template): Role
{
    return Role::query()->where('company_id', $companyId)->where('template_code', $template->value)->firstOrFail();
}

it('copie la matrice des rôles par défaut (docs/04 §2)', function () {
    $company = provisionedCompany();
    actingAsCompany($company);

    foreach (RoleTemplate::cases() as $template) {
        expect(roleOf($company->id, $template)->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect(PermissionCatalog::forTemplate($template))->sort()->values()->all());
    }

    expect(roleOf($company->id, RoleTemplate::Seller)->permissions->pluck('name'))->not->toContain('reports.financial', 'products.view_sensitive');
});

it('réserve la gestion des utilisateurs aux rôles autorisés', function () {
    $company = provisionedCompany();
    $seller = userWithRole($company, RoleTemplate::Seller);

    loginAs($seller)->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('code', 'forbidden');
    loginAs($seller)->getJson('/api/v1/roles')->assertForbidden();
});

it('modifie les rôles d\'un utilisateur et l\'audite', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $user = userWithRole($company, RoleTemplate::Seller);
    $storekeeper = roleOf($company->id, RoleTemplate::Storekeeper);

    loginAs($admin)->patchJson("/api/v1/users/{$user->id}", ['role_ids' => [$storekeeper->id], 'name' => 'Moussa Ndiaye'])
        ->assertOk()->assertJsonPath('data.name', 'Moussa Ndiaye')->assertJsonPath('data.roles.0.id', $storekeeper->id);

    expect(DB::table('audit_logs')->where(['action' => 'user.roles_changed', 'subject_id' => $user->id])->exists())->toBeTrue();
});

it('désactive un utilisateur : sa session est invalidée immédiatement (ADM-003)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $user = userWithRole($company, RoleTemplate::Seller);
    $epoch = $user->session_epoch;

    loginAs($admin)->postJson("/api/v1/users/{$user->id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');

    loginAs($user->refresh(), epoch: $epoch)->getJson('/api/v1/auth/me')
        ->assertStatus(401)->assertJsonPath('code', 'session_expired');

    loginAs($admin)->postJson("/api/v1/users/{$user->id}/enable")->assertOk()->assertJsonPath('data.status', 'active');
});

it('protège le dernier administrateur actif (ADM-005)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $secondAdmin = userWithRole($company);
    $seller = roleOf($company->id, RoleTemplate::Seller);

    loginAs($admin)->postJson("/api/v1/users/{$admin->id}/disable")->assertStatus(422)->assertJsonPath('code', 'cannot_disable_self');

    loginAs($admin)->postJson("/api/v1/users/{$secondAdmin->id}/disable")->assertOk();

    // $admin est désormais le seul administrateur actif : il ne peut pas perdre ce rôle.
    loginAs($admin)->patchJson("/api/v1/users/{$admin->id}", ['role_ids' => [$seller->id]])
        ->assertStatus(422)->assertJsonPath('code', 'last_admin');
});

it('crée un rôle personnalisé sans permission de plateforme (ADM-004)', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);

    loginAs($admin)->postJson('/api/v1/roles', ['name' => 'Caissier', 'permissions' => ['customer_payments.create', 'invoices.view']])
        ->assertCreated()->assertJsonPath('data.permissions', ['customer_payments.create', 'invoices.view']);

    loginAs($admin)->postJson('/api/v1/roles', ['name' => 'Pirate', 'permissions' => ['platform.companies.manage']])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/permissions/0');

    loginAs($admin)->postJson('/api/v1/roles', ['name' => 'Caissier', 'permissions' => []])->assertStatus(422);
});

it('verrouille le rôle Administrateur et refuse de supprimer un rôle attribué', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $adminRole = roleOf($company->id, RoleTemplate::CompanyAdmin);

    loginAs($admin)->patchJson("/api/v1/roles/{$adminRole->id}", ['permissions' => ['users.view']])
        ->assertStatus(409)->assertJsonPath('code', 'role_locked');

    $custom = loginAs($admin)->postJson('/api/v1/roles', ['name' => 'Caissier', 'permissions' => ['invoices.view']])->json('data.id');
    $user = userWithRole($company, RoleTemplate::Seller);
    loginAs($admin)->patchJson("/api/v1/users/{$user->id}", ['role_ids' => [$custom]])->assertOk();

    loginAs($admin)->deleteJson("/api/v1/roles/{$custom}")->assertStatus(409)->assertJsonPath('code', 'role_in_use');

    loginAs($admin)->patchJson("/api/v1/users/{$user->id}", ['role_ids' => [roleOf($company->id, RoleTemplate::Seller)->id]])->assertOk();
    loginAs($admin)->deleteJson("/api/v1/roles/{$custom}")->assertNoContent();
});

it('donne effet immédiat à un changement de permissions d\'un rôle', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $seller = userWithRole($company, RoleTemplate::Seller);
    $sellerRole = roleOf($company->id, RoleTemplate::Seller);

    loginAs($seller)->getJson('/api/v1/audit-logs')->assertForbidden();

    loginAs($admin)->patchJson("/api/v1/roles/{$sellerRole->id}", ['permissions' => ['audit.view', 'products.view']])->assertOk();

    loginAs($seller->refresh())->getJson('/api/v1/audit-logs')->assertOk();
});

it('liste le catalogue des permissions attribuables, sans les permissions de plateforme', function () {
    $admin = userWithRole(provisionedCompany());

    $names = collect(loginAs($admin)->getJson('/api/v1/permissions')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('users.manage')->not->toContain('platform.companies.manage');
});
