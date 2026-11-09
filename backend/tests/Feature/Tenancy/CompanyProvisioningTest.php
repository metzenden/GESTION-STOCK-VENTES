<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Infrastructure\Mail\InvitationMail;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| ADM-001, L1-09 : création atomique d'une entreprise ; invitation envoyée après validation.
*/

function companyPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'FDS',
        'trade_name' => 'FDS Business Dakar',
        'reference_currency' => 'XOF',
        'admin_email' => 'gerant@fds.example',
        'city' => 'Dakar',
    ], $overrides);
}

it('crée une entreprise complète : paramètres, numérotation, rôles et invitation de l\'administrateur', function () {
    Mail::fake();

    $response = loginAs(superAdmin())->postJson('/api/v1/admin/companies', companyPayload(), ['Idempotency-Key' => Str::random(24)]);

    $response->assertCreated()->assertJsonPath('data.code', 'FDS')->assertJsonPath('data.status', 'active')->assertHeader('Location');
    $companyId = $response->json('data.id');

    expect(DB::table('company_settings')->where('company_id', $companyId)->exists())->toBeTrue()
        ->and(DB::table('document_sequence_formats')->where('company_id', $companyId)->count())->toBe(count(DocumentType::cases()))
        ->and(DB::table('roles')->where('company_id', $companyId)->pluck('template_code')->sort()->values()->all())
        ->toBe(['accountant', 'company_admin', 'seller', 'storekeeper'])
        ->and(DB::table('tax_rates')->where('company_id', $companyId)->count())->toBe(0)
        ->and(DB::table('invitations')->where(['company_id' => $companyId, 'email' => 'gerant@fds.example'])->exists())->toBeTrue();

    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('gerant@fds.example') && $mail->roleName === 'Administrateur');
});

it('n\'envoie l\'invitation qu\'après la validation de la transaction', function () {
    $mail = new InvitationMail('A', 'B', 'jeton', 'demain');

    expect($mail->afterCommit)->toBeTrue();
});

it('annule tout si une étape échoue à mi-parcours, sans aucune donnée résiduelle', function () {
    Mail::fake();
    app()->bind('failing-step', fn () => new class implements CompanyProvisioningStep
    {
        public function priority(): int
        {
            return 15;
        }

        public function provision(string $companyId): void
        {
            throw new RuntimeException('Échec simulé');
        }
    });
    app()->tag(['failing-step'], CompanyProvisioningStep::TAG);

    loginAs(superAdmin())->postJson('/api/v1/admin/companies', companyPayload(), ['Idempotency-Key' => Str::random(24)])
        ->assertStatus(500);

    expect(DB::table('companies')->where('code', 'FDS')->exists())->toBeFalse()
        ->and(DB::table('company_settings')->count())->toBe(0)
        ->and(DB::table('roles')->whereNotNull('company_id')->count())->toBe(0)
        ->and(DB::table('invitations')->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('exige une clé d\'idempotence et rejoue la création sans doublon', function () {
    Mail::fake();
    $admin = superAdmin();

    loginAs($admin)->postJson('/api/v1/admin/companies', companyPayload())->assertStatus(428);

    $key = Str::random(24);
    $first = loginAs($admin)->postJson('/api/v1/admin/companies', companyPayload(), ['Idempotency-Key' => $key])->assertCreated();
    $replay = loginAs($admin)->postJson('/api/v1/admin/companies', companyPayload(), ['Idempotency-Key' => $key])->assertCreated();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('companies')->where('code', 'FDS')->count())->toBe(1);
    $replay->assertHeader('Idempotent-Replayed', 'true');
});

it('refuse un code d\'entreprise déjà pris', function () {
    Mail::fake();
    provisionedCompany(['code' => 'FDS']);

    loginAs(superAdmin())->postJson('/api/v1/admin/companies', companyPayload(), ['Idempotency-Key' => Str::random(24)])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/code');
});
