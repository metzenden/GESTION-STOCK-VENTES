<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Support\Facades\DB;

/*
| TEST-018, L1-12, D-041 : une entreprise suspendue est consultable mais n'accepte aucune écriture métier,
| y compris par le super-admin ; seule la plateforme peut la réactiver.
*/

it('suspend puis réactive une entreprise, avec motif et audit', function () {
    $company = provisionedCompany();
    $admin = superAdmin();

    loginAs($admin)->postJson("/api/v1/admin/companies/{$company->id}/suspend", ['reason' => 'Impayé'])
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension_reason', 'Impayé');

    loginAs($admin)->postJson("/api/v1/admin/companies/{$company->id}/suspend", ['reason' => 'Encore'])
        ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

    loginAs($admin)->postJson("/api/v1/admin/companies/{$company->id}/reactivate")
        ->assertOk()->assertJsonPath('data.status', 'active');

    expect(DB::table('audit_logs')->where(['subject_id' => $company->id, 'action' => 'company.updated'])->count())->toBe(2);
});

it('refuse toute écriture dans une entreprise suspendue mais autorise la lecture', function () {
    $company = provisionedCompany();
    $user = userWithRole($company, RoleTemplate::CompanyAdmin);
    $company->forceFill(['status' => 'suspended'])->save();

    loginAs($user)->getJson('/api/v1/users')->assertOk();
    loginAs($user)->getJson('/api/v1/settings/company')->assertOk();

    loginAs($user)->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '5'])
        ->assertStatus(423)->assertJsonPath('code', 'company_suspended');
    loginAs($user)->patchJson('/api/v1/settings/company', ['settings' => ['invoice_footer' => 'x']])->assertStatus(423);
});

it('oppose aussi la suspension au super-admin pour les écritures métier', function () {
    $company = provisionedCompany();
    $company->forceFill(['status' => 'suspended'])->save();
    $admin = superAdmin();

    loginAs($admin)->postJson('/api/v1/context/company', ['company_id' => $company->id])->assertOk();
    $this->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '5'])->assertStatus(423);

    // La réactivation, action de plateforme, reste possible.
    $this->postJson("/api/v1/admin/companies/{$company->id}/reactivate")->assertOk();
    $this->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '5'])->assertCreated();
});
