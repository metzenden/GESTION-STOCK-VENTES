<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| L1-14 : paramètres modifiables sans code (CDC §1.4) — identité, préfixes de numérotation, taux de change, taxes.
*/

it('modifie l\'identité et les paramètres de l\'entreprise', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);

    loginAs($admin)->patchJson('/api/v1/settings/company', [
        'identity' => ['trade_name' => 'FDS Thiès', 'ninea' => '123456789'],
        'settings' => ['default_payment_terms_days' => 30, 'invoice_footer' => 'Merci de votre confiance'],
    ])->assertOk()
        ->assertJsonPath('data.identity.trade_name', 'FDS Thiès')
        ->assertJsonPath('data.identity.code', $company->code)
        ->assertJsonPath('data.settings.default_payment_terms_days', 30)
        ->assertJsonPath('data.settings.rounding_mode', 'half_up');

    expect(DB::table('audit_logs')->where(['action' => 'company_settings.updated', 'company_id' => $company->id])->exists())->toBeTrue();
});

it('réserve la modification des paramètres à la permission settings.manage', function () {
    $company = provisionedCompany();
    $accountant = userWithRole($company, RoleTemplate::Accountant);

    loginAs($accountant)->patchJson('/api/v1/settings/company', ['settings' => ['invoice_footer' => 'x']])->assertForbidden();
});

it('change le préfixe de facture : les numéros suivants l\'utilisent, le compteur continue', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);
    $allocator = app(DocumentNumberAllocator::class);
    $today = Carbon::now('Africa/Dakar');
    $year = $today->format('Y');

    $first = DB::transaction(fn () => $allocator->next($company->id, DocumentType::Invoice, $today));
    expect($first)->toBe("FAC-{$year}-0001");

    loginAs($admin)->patchJson('/api/v1/settings/document-sequences', [
        'formats' => [['doc_type' => 'invoice', 'prefix' => 'FDSF', 'padding' => 5]],
    ])->assertOk()->assertJsonFragment(['doc_type' => 'invoice', 'prefix' => 'FDSF', 'padding' => 5]);

    $second = DB::transaction(fn () => $allocator->next($company->id, DocumentType::Invoice, $today));
    expect($second)->toBe("FDSF-{$year}-00002");

    loginAs($admin)->patchJson('/api/v1/settings/document-sequences', [
        'formats' => [['doc_type' => 'invoice', 'prefix' => 'fac-', 'padding' => 4]],
    ])->assertStatus(422);
});

it('enregistre des taux de change datés et refuse un doublon ou un taux nul', function () {
    $admin = userWithRole(provisionedCompany());

    loginAs($admin)->postJson('/api/v1/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'XOF', 'rate' => '655.957', 'valid_on' => '2026-11-05'])
        ->assertCreated()->assertJsonPath('data.rate', '655.957');

    loginAs($admin)->postJson('/api/v1/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'XOF', 'rate' => '656', 'valid_on' => '2026-11-05'])
        ->assertStatus(422);
    loginAs($admin)->postJson('/api/v1/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'XOF', 'rate' => '0', 'valid_on' => '2026-11-05'])
        ->assertStatus(422);
    loginAs($admin)->postJson('/api/v1/exchange-rates', ['from_currency' => 'XOF', 'to_currency' => 'XOF', 'rate' => '1', 'valid_on' => '2026-11-05'])
        ->assertStatus(422);

    loginAs($admin)->getJson('/api/v1/exchange-rates')->assertOk()->assertJsonPath('meta.total', 1);
});

it('démarre sans taux de taxe et permet de les gérer (M-01)', function () {
    $admin = userWithRole(provisionedCompany());

    loginAs($admin)->getJson('/api/v1/tax-rates')->assertOk()->assertJsonCount(0, 'data');

    $id = loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Taxe locale', 'rate' => '18'])
        ->assertCreated()->assertJsonPath('data.rate', '18')->json('data.id');

    loginAs($admin)->patchJson("/api/v1/tax-rates/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Excessive', 'rate' => '150'])->assertStatus(422);
    loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Taxe locale', 'rate' => '5'])->assertStatus(422);
});
