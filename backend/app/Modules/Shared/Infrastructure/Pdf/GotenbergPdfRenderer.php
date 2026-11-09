<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Pdf;

use App\Modules\Shared\Contracts\PdfRenderer;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Rendu PDF A4 via Gotenberg (Chromium headless, JavaScript désactivé), D-032.
 */
final class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
    ) {}

    public function renderHtml(string $html): string
    {
        $response = $this->http
            ->timeout(60)
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=utf-8'])
            ->post(rtrim($this->baseUrl, '/').'/forms/chromium/convert/html', [
                'paperWidth' => '8.27',
                'paperHeight' => '11.7',
                'marginTop' => '0.4',
                'marginBottom' => '0.4',
                'marginLeft' => '0.4',
                'marginRight' => '0.4',
                'printBackground' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Échec de la génération PDF (Gotenberg HTTP {$response->status()}).");
        }

        $pdf = $response->body();

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Gotenberg a renvoyé un contenu qui n\'est pas un PDF.');
        }

        return $pdf;
    }
}
