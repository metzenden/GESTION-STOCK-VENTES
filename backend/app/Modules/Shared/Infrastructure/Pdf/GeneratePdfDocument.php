<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Pdf;

use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Génération PDF différée. Le job transporte explicitement l'entreprise et rétablit son contexte.
 */
final class GeneratePdfDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $companyId,
        public readonly string $docType,
        public readonly string $sourceType,
        public readonly string $sourceId,
        /** @var view-string */
        public readonly string $view,
        public readonly array $data,
        public readonly string $fileName,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(TenantContext $tenant, GeneratedDocumentService $documents): void
    {
        $tenant->runAs($this->companyId, fn () => $documents->generate(
            $this->docType, $this->sourceType, $this->sourceId, $this->view, $this->data, $this->fileName,
        ));
    }
}
