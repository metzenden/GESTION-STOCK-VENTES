<?php

declare(strict_types=1);

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    $this->storage = app(PrivateFileStorage::class);
});

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

it('stocke un fichier sous le répertoire privé de l\'entreprise avec un nom imprévisible', function () {
    $company = actingAsCompany(Company::factory()->create());

    $file = $this->storage->storeContents(base64_decode(PNG_1PX), '../../photo produit.png', FilePurpose::ProductImage);

    expect($file->company_id)->toBe($company->id)
        ->and($file->path)->toStartWith("companies/{$company->id}/")
        ->and($file->path)->toMatch('/\/[0-9A-HJKMNP-TV-Z]{26}\.png$/')
        ->and($file->original_name)->toBe('photo produit.png')
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->sha256)->toBe(hash('sha256', base64_decode(PNG_1PX)));

    Storage::disk('private')->assertExists($file->path);
});

it('détecte le type réel et refuse une extension falsifiée', function () {
    actingAsCompany(Company::factory()->create());

    $this->storage->storeUpload(UploadedFile::fake()->createWithContent('facture.png', '<?php echo "pirate";'), FilePurpose::ProductImage);
})->throws(BusinessRuleViolation::class, 'format');

it('refuse un fichier trop volumineux', function () {
    actingAsCompany(Company::factory()->create());

    $this->storage->storeContents(str_repeat('a', FilePurpose::Logo->maxBytes() + 1), 'logo.png', FilePurpose::Logo);
})->throws(BusinessRuleViolation::class, 'taille maximale');

it('MT-009 rend introuvable le fichier d\'une autre entreprise', function () {
    actingAsCompany(Company::factory()->create());
    $fileOfB = app(TenantContext::class)->runAs(
        Company::factory()->create()->id,
        fn () => $this->storage->storeContents(base64_decode(PNG_1PX), 'b.png', FilePurpose::ProductImage),
    );

    $this->storage->findForCurrentCompany($fileOfB->id);
})->throws(ModelNotFoundException::class);

it('refuse la lecture d\'un fichier d\'une autre entreprise même avec le modèle en main', function () {
    $fileOfB = app(TenantContext::class)->runAs(
        Company::factory()->create()->id,
        fn () => $this->storage->storeContents(base64_decode(PNG_1PX), 'b.png', FilePurpose::ProductImage),
    );
    actingAsCompany(Company::factory()->create());

    $this->storage->contents($fileOfB);
})->throws(ModelNotFoundException::class);

it('refuse tout stockage sans entreprise active', function () {
    $this->storage->storeContents(base64_decode(PNG_1PX), 'x.png', FilePurpose::ProductImage);
})->throws(LogicException::class);
