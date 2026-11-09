<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Pdf;

use App\Modules\Shared\Contracts\PdfRenderer;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Génère un document PDF à partir d'une vue Blade, le stocke en privé et l'enregistre dans
 * generated_documents avec son empreinte SHA-256 et un numéro de révision.
 */
final class GeneratedDocumentService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly PrivateFileStorage $files,
        private readonly ViewFactory $views,
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @return array{id: string, file_id: string, revision: int, sha256: string}
     */
    public function generate(string $docType, string $sourceType, string $sourceId, string $view, array $data, string $fileName): array
    {
        $companyId = $this->tenant->requireCompanyId();
        $pdf = $this->renderer->renderHtml($this->views->make($view, $data)->render());

        return $this->db->transaction(function () use ($companyId, $docType, $sourceType, $sourceId, $pdf, $fileName) {
            $revision = 1 + (int) $this->db->table('generated_documents')
                ->where(['company_id' => $companyId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'doc_type' => $docType])
                ->lockForUpdate()
                ->max('revision');

            $file = $this->files->storeContents($pdf, $fileName, FilePurpose::GeneratedPdf);
            $id = (string) Str::ulid();
            $sha256 = hash('sha256', $pdf);

            $this->db->table('generated_documents')->insert([
                'id' => $id,
                'company_id' => $companyId,
                'doc_type' => $docType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'revision' => $revision,
                'file_id' => $file->id,
                'content_sha256' => $sha256,
            ]);

            return ['id' => $id, 'file_id' => $file->id, 'revision' => $revision, 'sha256' => $sha256];
        });
    }
}
