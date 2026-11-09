<?php

declare(strict_types=1);

use App\Modules\Settings\Infrastructure\Models\TaxRate;
use App\Modules\Shared\Contracts\TenantAware;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Cache\TenantCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| L1-02 : contexte d'entreprise, portée ORM « fail closed », tâches TenantAware, cache préfixé.
*/

it('refuse toute requête sur un modèle d\'entreprise sans contexte (fail closed)', function () {
    app(TenantContext::class)->set(null);

    TaxRate::query()->count();
})->throws(LogicException::class, 'Aucune entreprise active');

it('ne renvoie que les données de l\'entreprise active', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();

    actingAsCompany($a);
    TaxRate::query()->create(['name' => 'Taxe A', 'rate' => '1']);
    actingAsCompany($b);
    TaxRate::query()->create(['name' => 'Taxe B', 'rate' => '2']);

    actingAsCompany($a);
    expect(TaxRate::query()->pluck('name')->all())->toBe(['Taxe A']);
});

it('impose l\'entreprise active à la création et refuse une autre entreprise', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    actingAsCompany($a);

    $tax = TaxRate::query()->create(['name' => 'Taxe', 'rate' => '1']);
    expect($tax->company_id)->toBe($a->id);

    $other = new TaxRate(['name' => 'Intrus', 'rate' => '1']);
    $other->forceFill(['company_id' => $b->id])->save();
})->throws(LogicException::class, 'company_id différent');

it('interdit de changer l\'entreprise d\'une ligne', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    actingAsCompany($a);
    $tax = TaxRate::query()->create(['name' => 'Taxe', 'rate' => '1']);

    $tax->forceFill(['company_id' => $b->id])->save();
})->throws(LogicException::class, 'n\'est pas modifiable');

it('rejette en base une relation vers une autre entreprise (FK composite)', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();
    $roleOfB = DB::table('roles')->where('company_id', $b->id)->value('id');

    DB::table('invitations')->insert([
        'id' => (string) Str::ulid(), 'company_id' => $a->id, 'email' => 'x@example.com', 'role_id' => $roleOfB,
        'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
    ]);
})->throws(QueryException::class);

it('préfixe les clés de cache par entreprise', function () {
    $a = provisionedCompany();
    $b = provisionedCompany();

    actingAsCompany($a);
    app(TenantCache::class)->put('compteur', 'valeur de A');
    expect(app(TenantCache::class)->key('compteur'))->toBe("c:{$a->id}:compteur");

    actingAsCompany($b);
    expect(app(TenantCache::class)->get('compteur'))->toBeNull();
});

final class RecordTaxRateJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $companyId, public string $name) {}

    public function tenantCompanyId(): string
    {
        return $this->companyId;
    }

    public function writesBusinessData(): bool
    {
        return true;
    }

    public function handle(): void
    {
        TaxRate::query()->create(['name' => $this->name, 'rate' => '0']);
    }
}

it('rétablit le contexte de l\'entreprise dans une tâche de fond et n\'écrit que chez elle', function () {
    $a = provisionedCompany();
    app(TenantContext::class)->set(null);

    RecordTaxRateJob::dispatch($a->id, 'Depuis la file');

    expect(DB::table('tax_rates')->where('company_id', $a->id)->value('name'))->toBe('Depuis la file')
        ->and(app(TenantContext::class)->companyId())->toBeNull();
});

it('refuse une tâche d\'écriture pour une entreprise suspendue (GEN-004)', function () {
    $a = provisionedCompany();
    $a->forceFill(['status' => 'suspended'])->save();

    RecordTaxRateJob::dispatch($a->id, 'Refusée');
})->throws(BusinessRuleViolation::class, 'suspendue');
