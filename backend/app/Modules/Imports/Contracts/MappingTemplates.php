<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

interface MappingTemplates
{
    /** @return array{id: string, import_type: string, supplier_id: string|null, name: string, file_format: string, mapping: array<string, mixed>}|null */
    public function find(string $templateId): ?array;

    /**
     * Modèle le plus récent pour ce type et ce fournisseur, le cas échéant.
     *
     * @return array{id: string, import_type: string, supplier_id: string|null, name: string, file_format: string, mapping: array<string, mixed>}|null
     */
    public function latestFor(string $importType, ?string $supplierId, string $format): ?array;

    /** @param array<string, mixed> $mapping */
    public function remember(string $importType, ?string $supplierId, string $name, string $format, array $mapping, ?string $actorId): string;
}
