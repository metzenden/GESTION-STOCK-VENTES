<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| L1-08 : journal d'audit en ajout seul, valeurs avant/après, consultation filtrée par entreprise.
*/

it('journalise les valeurs avant et après d\'une donnée de référence, sans secret', function () {
    $company = provisionedCompany();
    $admin = userWithRole($company);

    $id = loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '5'])->json('data.id');
    loginAs($admin)->patchJson("/api/v1/tax-rates/{$id}", ['rate' => '7'])->assertOk();

    $log = DB::table('audit_logs')->where(['action' => 'tax_rate.updated', 'subject_id' => $id])->first();

    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->request_id)->not->toBeNull()
        ->and((float) json_decode($log->old_values, true)['rate'])->toBe(5.0)
        ->and(json_decode($log->new_values, true)['rate'])->toBe('7');

    $userLog = DB::table('audit_logs')->where(['action' => 'user.created', 'subject_id' => $admin->id])->value('new_values');
    expect($userLog)->not->toContain('password');
});

it('interdit toute modification ou suppression du journal', function (string $sql) {
    $admin = userWithRole(provisionedCompany());
    loginAs($admin)->postJson('/api/v1/tax-rates', ['name' => 'Taxe', 'rate' => '5']);

    DB::statement($sql);
})->with([
    'UPDATE' => ["UPDATE audit_logs SET action = 'falsifié'"],
    'DELETE' => ['DELETE FROM audit_logs'],
])->throws(QueryException::class, 'ajout seul');

it('ne montre à une entreprise que son propre journal', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    $adminA = userWithRole($a);
    $adminB = userWithRole($b);
    loginAs($adminB)->postJson('/api/v1/tax-rates', ['name' => 'Secret B', 'rate' => '5'])->assertCreated();

    $response = loginAs($adminA)->getJson('/api/v1/audit-logs?per_page=100')->assertOk();

    expect(collect($response->json('data'))->pluck('company_id')->unique()->all())->toBe([$a->id])
        ->and($response->getContent())->not->toContain('Secret B');
});

it('réserve le journal à la permission audit.view', function () {
    $company = provisionedCompany();

    loginAs(userWithRole($company, RoleTemplate::Seller))->getJson('/api/v1/audit-logs')->assertForbidden();
    loginAs(userWithRole($company, RoleTemplate::Accountant))->getJson('/api/v1/audit-logs')->assertOk();
});
