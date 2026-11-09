<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function allocate(Company $company, DocumentType $type, string $date = '2026-11-05'): string
{
    return DB::transaction(fn () => app(DocumentNumberAllocator::class)
        ->next($company->id, $type, CarbonImmutable::parse($date)));
}

it('numérote les factures au format FAC-AAAA-0001 de façon continue', function () {
    $company = Company::factory()->create();

    expect(allocate($company, DocumentType::Invoice))->toBe('FAC-2026-0001')
        ->and(allocate($company, DocumentType::Invoice))->toBe('FAC-2026-0002')
        ->and(allocate($company, DocumentType::Invoice))->toBe('FAC-2026-0003');
});

it('MT-006 tient une séquence indépendante par entreprise', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    allocate($a, DocumentType::Invoice);
    allocate($a, DocumentType::Invoice);

    expect(allocate($b, DocumentType::Invoice))->toBe('FAC-2026-0001');
});

it('repart à 1 chaque année et sépare les types de documents', function () {
    $company = Company::factory()->create();

    allocate($company, DocumentType::Invoice, '2026-12-31');

    expect(allocate($company, DocumentType::Invoice, '2027-01-01'))->toBe('FAC-2027-0001')
        ->and(allocate($company, DocumentType::CreditNote, '2027-01-01'))->toBe('AV-2027-0001');
});

it('ne consomme pas de numéro si la transaction du document est annulée', function () {
    $company = Company::factory()->create();

    try {
        DB::transaction(function () use ($company) {
            app(DocumentNumberAllocator::class)->next($company->id, DocumentType::Invoice, CarbonImmutable::parse('2026-11-05'));
            throw new RuntimeException('échec simulé');
        });
    } catch (RuntimeException) {
    }

    expect(allocate($company, DocumentType::Invoice))->toBe('FAC-2026-0001');
});

it('refuse une attribution hors transaction', function () {
    $company = Company::factory()->create();

    // RefreshDatabase ouvre déjà une transaction : on la referme pour ce contrôle.
    DB::rollBack();

    try {
        app(DocumentNumberAllocator::class)->next($company->id, DocumentType::Invoice, CarbonImmutable::parse('2026-11-05'));
    } finally {
        DB::beginTransaction();
    }
})->throws(LogicException::class);

it('élargit le numéro au-delà de la largeur prévue sans tronquer', function () {
    expect(DocumentNumberAllocator::format('FAC', 2026, 10000, 4))->toBe('FAC-2026-10000')
        ->and(DocumentNumberAllocator::format('LOT', 2026, 123, 6))->toBe('LOT-2026-000123');
});
