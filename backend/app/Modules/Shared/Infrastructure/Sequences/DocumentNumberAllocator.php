<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Sequences;

use App\Modules\Shared\Domain\Enums\DocumentType;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Attribue les numéros de documents (FAC-2026-0001…) de façon atomique (D-030, TEST-021).
 *
 * Doit être appelé à l'intérieur de la transaction qui crée le document : le numéro n'est
 * consommé que si la transaction est validée, ce qui exclut collisions et trous.
 *
 * Création d'une séquence absente : la ligne de l'entreprise est verrouillée avant la lecture
 * verrouillante de la séquence. Aucune transaction ne détient alors de verrou d'intervalle sur
 * document_sequences en attendant ce verrou, ce qui évite l'interblocage classique de
 * « SELECT … FOR UPDATE puis INSERT » concurrents.
 */
final class DocumentNumberAllocator
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DocumentSequenceFormats $formats,
    ) {}

    /** @param DateTimeInterface $documentDate date du document, déjà exprimée dans le fuseau de l'entreprise */
    public function next(string $companyId, DocumentType $type, DateTimeInterface $documentDate): string
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('L\'attribution d\'un numéro de document doit se faire dans une transaction.');
        }

        $year = $type->isYearly() ? (int) $documentDate->format('Y') : 0;

        $key = ['company_id' => $companyId, 'doc_type' => $type->value, 'period_year' => $year];

        // Lecture non verrouillante : détermine seulement s'il faut créer la séquence.
        $exists = $this->db->table('document_sequences')->where($key)->exists();

        if (! $exists) {
            $this->db->table('companies')->where('id', $companyId)->lockForUpdate()->value('id')
                ?? throw new LogicException("Entreprise inconnue : {$companyId}.");
        }

        $sequence = $this->db->table('document_sequences')->where($key)->lockForUpdate()->first();

        if ($sequence === null) {
            $number = 1;
            ['prefix' => $prefix, 'padding' => $padding] = $this->formats->for($companyId, $type);

            $this->db->table('document_sequences')->insert($key + [
                'id' => (string) Str::ulid(),
                'prefix' => $prefix,
                'padding' => $padding,
                'next_number' => 2,
            ]);
        } else {
            $number = (int) $sequence->next_number;
            $prefix = (string) $sequence->prefix;
            $padding = (int) $sequence->padding;

            $this->db->table('document_sequences')
                ->where('id', $sequence->id)
                ->update(['next_number' => $number + 1]);
        }

        return self::format($prefix, $year, $number, $padding);
    }

    public static function format(string $prefix, int $year, int $number, int $padding): string
    {
        $formatted = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        return $year > 0 ? "{$prefix}-{$year}-{$formatted}" : "{$prefix}-{$formatted}";
    }
}
