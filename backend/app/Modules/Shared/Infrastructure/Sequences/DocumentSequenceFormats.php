<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Sequences;

use App\Modules\Shared\Domain\Enums\DocumentType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Préfixes et largeurs de numérotation d'une entreprise (L1-14). Le compteur n'est jamais modifiable ici.
 */
final class DocumentSequenceFormats
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** Crée les formats par défaut de chaque type de document (création d'entreprise, ADM-001). */
    public function initialize(string $companyId): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->db->table('document_sequence_formats')->insertOrIgnore([
                'id' => strtoupper((string) Str::ulid()),
                'company_id' => $companyId,
                'doc_type' => $type->value,
                'prefix' => $type->defaultPrefix(),
                'padding' => $type->defaultPadding(),
            ]);
        }
    }

    /** @return list<array{doc_type: string, prefix: string, padding: int}> */
    public function all(string $companyId): array
    {
        $stored = $this->db->table('document_sequence_formats')->where('company_id', $companyId)
            ->get(['doc_type', 'prefix', 'padding'])->keyBy('doc_type');

        $formats = [];

        foreach (DocumentType::cases() as $type) {
            $row = $stored->get($type->value);
            $formats[] = [
                'doc_type' => $type->value,
                'prefix' => $row !== null ? (string) $row->prefix : $type->defaultPrefix(),
                'padding' => $row !== null ? (int) $row->padding : $type->defaultPadding(),
            ];
        }

        return $formats;
    }

    /** @return array{prefix: string, padding: int} */
    public function for(string $companyId, DocumentType $type): array
    {
        $row = $this->db->table('document_sequence_formats')
            ->where(['company_id' => $companyId, 'doc_type' => $type->value])->first(['prefix', 'padding']);

        return $row !== null
            ? ['prefix' => (string) $row->prefix, 'padding' => (int) $row->padding]
            : ['prefix' => $type->defaultPrefix(), 'padding' => $type->defaultPadding()];
    }

    /**
     * Applique le nouveau format aux numéros futurs : séquences de l'année en cours et suivantes.
     * Les documents déjà numérotés conservent leur numéro.
     */
    public function update(string $companyId, DocumentType $type, string $prefix, int $padding, int $currentYear, ?string $userId): void
    {
        $this->db->transaction(function () use ($companyId, $type, $prefix, $padding, $currentYear, $userId) {
            $this->db->table('document_sequence_formats')->upsert([[
                'id' => strtoupper((string) Str::ulid()),
                'company_id' => $companyId,
                'doc_type' => $type->value,
                'prefix' => $prefix,
                'padding' => $padding,
                'updated_by' => $userId,
            ]], ['company_id', 'doc_type'], ['prefix', 'padding', 'updated_by']);

            $this->db->table('document_sequences')
                ->where(['company_id' => $companyId, 'doc_type' => $type->value])
                ->where('period_year', '>=', $currentYear)
                ->update(['prefix' => $prefix, 'padding' => $padding]);
        });
    }
}
