<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Pdf\GeneratePdfDocument;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('private');
    config(['services.gotenberg.url' => 'http://gotenberg.test']);
});

/** Faux Gotenberg : le premier faux enregistré l'emporte, il ne peut donc pas être placé dans beforeEach. */
function fakeGotenbergPdf(): void
{
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7 contenu factice', 200, ['Content-Type' => 'application/pdf'])]);
}

it('L0-14 génère un PDF A4 via job, le stocke et enregistre son empreinte', function () {
    fakeGotenbergPdf();
    $company = Company::factory()->create(['trade_name' => 'FDS Business']);
    $sourceId = (string) Str::ulid();

    GeneratePdfDocument::dispatchSync($company->id, 'report', 'test_source', $sourceId, 'pdf.sample',
        ['title' => 'Essai', 'body' => 'Bonjour', 'company' => ['trade_name' => 'FDS Business']], 'essai.pdf');

    $document = DB::table('generated_documents')->where('source_id', $sourceId)->first();

    expect($document)->not->toBeNull()
        ->and($document->company_id)->toBe($company->id)
        ->and($document->revision)->toBe(1)
        ->and($document->content_sha256)->toBe(hash('sha256', '%PDF-1.7 contenu factice'));

    $file = DB::table('files')->where('id', $document->file_id)->first();
    Storage::disk('private')->assertExists($file->path);

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/forms/chromium/convert/html')
            && str_contains($request->body(), 'FDS Business')
            && str_contains($request->body(), '8.27');
    });
});

it('incrémente la révision lors d\'une régénération du même document', function () {
    fakeGotenbergPdf();
    $company = Company::factory()->create();
    $sourceId = (string) Str::ulid();
    $data = ['title' => 'Essai', 'body' => 'x', 'company' => []];

    GeneratePdfDocument::dispatchSync($company->id, 'report', 'test_source', $sourceId, 'pdf.sample', $data, 'a.pdf');
    GeneratePdfDocument::dispatchSync($company->id, 'report', 'test_source', $sourceId, 'pdf.sample', $data, 'a.pdf');

    expect(DB::table('generated_documents')->where('source_id', $sourceId)->pluck('revision')->sort()->values()->all())->toBe([1, 2]);
});

it('échoue proprement si le service PDF ne renvoie pas un PDF', function () {
    Http::fake(['gotenberg.test/*' => Http::response('<html>erreur</html>', 200)]);
    $company = Company::factory()->create();

    GeneratePdfDocument::dispatchSync($company->id, 'report', 'test_source', (string) Str::ulid(), 'pdf.sample',
        ['title' => 'x', 'body' => 'x', 'company' => []], 'a.pdf');
})->throws(RuntimeException::class, "n'est pas un PDF");
