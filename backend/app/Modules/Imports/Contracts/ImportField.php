<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

/** Champ cible d'un type d'import : nom, type de valeur et synonymes d'en-tête reconnus automatiquement. */
final readonly class ImportField
{
    public const TEXT = 'text';

    public const INTEGER = 'integer';

    public const DECIMAL = 'decimal';

    /** @param list<string> $aliases */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public array $aliases = [],
        public bool $required = false,
    ) {}
}
