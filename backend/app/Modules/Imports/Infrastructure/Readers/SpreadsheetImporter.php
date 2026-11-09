<?php

declare(strict_types=1);

namespace App\Modules\Imports\Infrastructure\Readers;

use App\Modules\Imports\Contracts\ImportField;
use App\Modules\Imports\Contracts\MappedRow;
use App\Modules\Imports\Contracts\TabularImporter;
use App\Modules\Imports\Domain\CellValueParser;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class SpreadsheetImporter implements TabularImporter
{
    private const HEADER_SCAN_ROWS = 20;

    public function read(string $path, string $format, array $fields, ?array $mapping = null): array
    {
        $grid = match ($format) {
            'csv' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            default => throw BusinessRuleViolation::invalidField('file', 'Format non pris en charge : CSV ou Excel (.xlsx) attendu.'),
        };

        if ($grid === []) {
            throw BusinessRuleViolation::invalidField('file', 'Le fichier est vide.');
        }

        $headerIndex = isset($mapping['header_row']) ? max(0, (int) $mapping['header_row'] - 1) : $this->detectHeader($grid, $fields);
        $headers = array_map(fn ($h) => trim((string) $h), $grid[$headerIndex] ?? []);
        $columns = $this->resolveColumns($headers, $fields, $mapping['columns'] ?? []);
        $rows = [];

        foreach (array_slice($grid, $headerIndex + 1, null, true) as $index => $cells) {
            if (count($rows) >= self::MAX_ROWS) {
                throw BusinessRuleViolation::invalidField('file', 'Le fichier dépasse '.self::MAX_ROWS.' lignes.');
            }

            $raw = [];

            foreach ($headers as $i => $header) {
                $raw[$header !== '' ? $header : self::letter($i)] = isset($cells[$i]) ? (string) $cells[$i] : null;
            }

            $values = [];
            $errors = [];

            foreach ($fields as $field) {
                $column = $columns[$field->name] ?? null;
                $cell = $column === null ? null : (isset($cells[$column]) ? (string) $cells[$column] : null);
                [$value, $error] = match ($field->type) {
                    ImportField::INTEGER => CellValueParser::integer($cell),
                    ImportField::DECIMAL => CellValueParser::decimal($cell),
                    default => [CellValueParser::text($cell), null],
                };

                if ($error !== null) {
                    $errors[] = ['field' => $field->name, 'message' => $error];
                } elseif ($value === null && $field->required) {
                    $errors[] = ['field' => $field->name, 'message' => 'Valeur obligatoire manquante.'];
                }

                $values[$field->name] = $value;
            }

            $row = new MappedRow($index + 1, $raw, $values, $errors);

            if (! $row->isBlank()) {
                $rows[] = $row;
            }
        }

        $unmapped = array_values(array_map(fn (ImportField $f) => $f->name, array_filter($fields, fn (ImportField $f) => $f->required && ! isset($columns[$f->name]))));

        return [
            'headers' => $headers,
            'columns' => array_map(fn (int $i) => $headers[$i] !== '' ? $headers[$i] : self::letter($i), $columns),
            'rows' => $rows,
            'unmapped_required' => $unmapped,
        ];
    }

    /** @return list<list<string|null>> */
    private function csv(string $path): array
    {
        $contents = (string) file_get_contents($path);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = ';';
        $best = -1;

        foreach ([';', ',', "\t", '|'] as $candidate) {
            if (substr_count($firstLine, $candidate) > $best) {
                $best = substr_count($firstLine, $candidate);
                $delimiter = $candidate;
            }
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $contents);
        rewind($handle);
        $grid = [];

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $grid[] = array_map(fn ($c) => $c === null ? null : (string) $c, $cells);

            if (count($grid) > self::MAX_ROWS + self::HEADER_SCAN_ROWS) {
                break;
            }
        }

        fclose($handle);

        return $grid;
    }

    /** @return list<list<string|null>> */
    private function xlsx(string $path): array
    {
        $reader = new XlsxReader;

        try {
            $reader->open($path);
        } catch (\Throwable) {
            throw BusinessRuleViolation::invalidField('file', 'Le classeur Excel est illisible.');
        }

        $grid = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $grid[] = array_map(fn ($v) => match (true) {
                        $v === null => null,
                        $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
                        is_bool($v) => $v ? '1' : '0',
                        is_float($v) => rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.'),
                        is_scalar($v) => (string) $v,
                        default => null,
                    }, $row->toArray());

                    if (count($grid) > self::MAX_ROWS + self::HEADER_SCAN_ROWS) {
                        break 2;
                    }
                }

                break; // première feuille uniquement
            }
        } finally {
            $reader->close();
        }

        return $grid;
    }

    /**
     * @param  list<list<string|null>>  $grid
     * @param  list<ImportField>  $fields
     */
    private function detectHeader(array $grid, array $fields): int
    {
        $bestIndex = 0;
        $bestScore = 0;

        foreach (array_slice($grid, 0, self::HEADER_SCAN_ROWS) as $index => $cells) {
            $score = count(array_filter(array_map(fn ($c) => $this->fieldFor((string) $c, $fields), $cells)));

            if ($score > $bestScore) {
                [$bestIndex, $bestScore] = [$index, $score];
            }
        }

        return $bestIndex;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<ImportField>  $fields
     * @param  array<string, string>  $explicit
     * @return array<string, int> champ → index de colonne
     */
    private function resolveColumns(array $headers, array $fields, array $explicit): array
    {
        $columns = [];
        $normalized = array_map(fn ($h) => CellValueParser::normalizeHeader($h), $headers);

        foreach ($explicit as $field => $column) {
            if (preg_match('/^[A-Z]{1,3}$/', $column) === 1) {
                $columns[$field] = self::index($column);
            } elseif (($i = array_search(CellValueParser::normalizeHeader($column), $normalized, true)) !== false) {
                $columns[$field] = (int) $i;
            }
        }

        foreach ($headers as $i => $header) {
            $field = $this->fieldFor($header, $fields);

            if ($field !== null && ! isset($columns[$field]) && ! in_array($i, $columns, true)) {
                $columns[$field] = $i;
            }
        }

        return $columns;
    }

    /** @param list<ImportField> $fields */
    private function fieldFor(string $header, array $fields): ?string
    {
        $key = CellValueParser::normalizeHeader($header);

        if ($key === '') {
            return null;
        }

        foreach ($fields as $field) {
            foreach ([$field->name, ...$field->aliases] as $alias) {
                if (CellValueParser::normalizeHeader($alias) === $key) {
                    return $field->name;
                }
            }
        }

        return null;
    }

    private static function letter(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)).$letter;
        }

        return $letter;
    }

    private static function index(string $letter): int
    {
        $n = 0;

        foreach (str_split($letter) as $char) {
            $n = $n * 26 + (ord($char) - 64);
        }

        return $n - 1;
    }
}
