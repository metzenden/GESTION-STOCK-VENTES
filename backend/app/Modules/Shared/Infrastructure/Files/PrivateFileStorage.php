<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Files;

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Stockage privé des fichiers par entreprise (MT-009, CDC §25.2) :
 * type MIME détecté sur le contenu (jamais l'extension fournie), taille plafonnée,
 * nom de stockage ULID non prédictible, accès uniquement via l'entreprise active.
 */
final class PrivateFileStorage
{
    public const DISK = 'private';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly TenantContext $tenant,
    ) {}

    public function storeUpload(UploadedFile $upload, FilePurpose $purpose): StoredFile
    {
        if (! $upload->isValid()) {
            throw $this->rejected('Le fichier n\'a pas pu être téléversé.');
        }

        $contents = (string) file_get_contents($upload->getRealPath());

        return $this->storeContents($contents, $upload->getClientOriginalName(), $purpose);
    }

    public function storeContents(string $contents, string $originalName, FilePurpose $purpose): StoredFile
    {
        $companyId = $this->tenant->requireCompanyId();
        $size = strlen($contents);

        if ($size === 0) {
            throw $this->rejected('Le fichier est vide.');
        }

        if ($size > $purpose->maxBytes()) {
            throw $this->rejected(sprintf('Le fichier dépasse la taille maximale autorisée (%d Mo).', intdiv($purpose->maxBytes(), 1024 * 1024)));
        }

        $mime = $this->detectMime($contents, $originalName);
        $extension = $purpose->allowedMimeTypes()[$mime] ?? null;

        if ($extension === null) {
            throw $this->rejected("Ce format de fichier n'est pas accepté ({$mime}).");
        }

        $id = strtoupper((string) Str::ulid());
        $now = Carbon::now();
        $path = sprintf('companies/%s/%s/%s/%s.%s', $companyId, $now->format('Y'), $now->format('m'), $id, $extension);

        $this->filesystems->disk(self::DISK)->put($path, $contents);

        // forceCreate : l'id fait partie du chemin, il est fixé avant l'écriture du fichier.
        return StoredFile::query()->forceCreate([
            'id' => $id,
            'company_id' => $companyId,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->sanitizeName($originalName),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash('sha256', $contents),
            'purpose' => $purpose,
        ]);
    }

    /** Recherche limitée à l'entreprise active : un fichier d'une autre entreprise est introuvable (D-054). */
    public function findForCurrentCompany(string $fileId): StoredFile
    {
        $file = StoredFile::query()
            ->where('company_id', $this->tenant->requireCompanyId())
            ->whereKey($fileId)
            ->first();

        return $file ?? throw (new ModelNotFoundException)->setModel(StoredFile::class, [$fileId]);
    }

    public function contents(StoredFile $file): string
    {
        $this->assertBelongsToCurrentCompany($file);

        return (string) $this->filesystems->disk($file->disk)->get($file->path);
    }

    public function download(StoredFile $file): StreamedResponse
    {
        $this->assertBelongsToCurrentCompany($file);

        return $this->filesystems->disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Supprime le fichier et sa fiche (les références doivent avoir été retirées avant). */
    public function delete(StoredFile $file): void
    {
        $this->assertBelongsToCurrentCompany($file);
        $file->delete();
        $this->filesystems->disk($file->disk)->delete($file->path);
    }

    /** Envoi pour affichage dans l'application (images), avec mise en cache privée. */
    public function inline(StoredFile $file): StreamedResponse
    {
        $this->assertBelongsToCurrentCompany($file);

        return $this->filesystems->disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function assertBelongsToCurrentCompany(StoredFile $file): void
    {
        if ($file->company_id !== $this->tenant->requireCompanyId()) {
            throw (new ModelNotFoundException)->setModel(StoredFile::class, [$file->id]);
        }
    }

    private function detectMime(string $contents, string $originalName): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        // Un XLSX est une archive ZIP : on vérifie sa structure plutôt que de croire l'extension.
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true)
            && str_ends_with(strtolower($originalName), '.xlsx')
            && $this->isXlsx($contents)) {
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return $mime;
    }

    private function isXlsx(string $contents): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');

        try {
            file_put_contents($tmp, $contents);
            $zip = new ZipArchive;

            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                return false;
            }

            $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();

            return $valid;
        } finally {
            @unlink($tmp);
        }
    }

    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'fichier', 200, '');
    }

    private function rejected(string $detail): BusinessRuleViolation
    {
        return new BusinessRuleViolation('file_rejected', 'Fichier refusé', $detail, 422);
    }
}
