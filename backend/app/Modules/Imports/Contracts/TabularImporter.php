<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

/**
 * Moteur d'import tabulaire (L3-05) : lecture CSV/XLSX → correspondance des colonnes → conversion et contrôle.
 * Il ne modifie jamais les données métier : le module appelant décide de l'écriture après revue (IMP-001).
 */
interface TabularImporter
{
    public const MAX_ROWS = 5000;

    /**
     * @param  list<ImportField>  $fields
     * @param  array{header_row?: int, columns?: array<string, string>}|null  $mapping  champ → en-tête (ou lettre de colonne)
     * @return array{headers: list<string>, columns: array<string, string>, rows: list<MappedRow>, unmapped_required: list<string>}
     */
    public function read(string $path, string $format, array $fields, ?array $mapping = null): array;
}
