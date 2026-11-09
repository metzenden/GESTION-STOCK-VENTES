<?php

declare(strict_types=1);

namespace App\Modules\Imports\Contracts;

/** Ligne lue et convertie ; les erreurs sont conservées pour la revue (jamais d'écriture silencieuse). */
final readonly class MappedRow
{
    /**
     * @param  array<string, string|null>  $raw  en-tête → valeur brute
     * @param  array<string, string|int|null>  $values  champ → valeur convertie
     * @param  list<array{field: string, message: string}>  $errors
     */
    public function __construct(
        public int $rowNumber,
        public array $raw,
        public array $values,
        public array $errors,
    ) {}

    public function isBlank(): bool
    {
        return array_filter($this->raw, fn ($v) => $v !== null && trim($v) !== '') === [];
    }
}
