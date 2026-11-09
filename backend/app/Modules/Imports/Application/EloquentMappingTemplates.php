<?php

declare(strict_types=1);

namespace App\Modules\Imports\Application;

use App\Modules\Imports\Contracts\MappingTemplates;
use App\Modules\Imports\Infrastructure\Models\ImportMappingTemplate;

final class EloquentMappingTemplates implements MappingTemplates
{
    public function find(string $templateId): ?array
    {
        $template = ImportMappingTemplate::query()->find($templateId);

        return $template === null ? null : self::toArray($template);
    }

    /** @return array{id: string, import_type: string, supplier_id: string|null, name: string, file_format: string, mapping: array<string, mixed>}|null */
    public function latestFor(string $importType, ?string $supplierId, string $format): ?array
    {
        $template = ImportMappingTemplate::query()->where('import_type', $importType)->where('file_format', $format)
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId), fn ($q) => $q->whereNull('supplier_id'))
            ->orderByDesc('updated_at')->first();

        return $template === null ? null : self::toArray($template);
    }

    public function remember(string $importType, ?string $supplierId, string $name, string $format, array $mapping, ?string $actorId): string
    {
        $template = ImportMappingTemplate::query()->firstOrNew(['import_type' => $importType, 'name' => $name]);
        $template->fill(['supplier_id' => $supplierId, 'file_format' => $format, 'mapping' => $mapping])
            ->forceFill([$template->exists ? 'updated_by' : 'created_by' => $actorId])->save();

        return $template->id;
    }

    /** @return array{id: string, import_type: string, supplier_id: string|null, name: string, file_format: string, mapping: array<string, mixed>} */
    public static function toArray(ImportMappingTemplate $t): array
    {
        return ['id' => $t->id, 'import_type' => $t->import_type, 'supplier_id' => $t->supplier_id, 'name' => $t->name, 'file_format' => $t->file_format, 'mapping' => $t->mapping];
    }
}
