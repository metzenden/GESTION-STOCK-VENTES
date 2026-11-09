<?php

declare(strict_types=1);

namespace App\Modules\Imports\Application;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Imports\Contracts\ImportField;
use App\Modules\Imports\Contracts\TabularImporter;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Sales\Contracts\CustomerDirectory;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Imports initiaux (L6-08, TEST-019) : produits, clients, fournisseurs. Tout ou rien : la moindre ligne
 * invalide annule l'import ; la simulation exécute tout puis annule la transaction (aucune écriture).
 */
final class InitialImportService
{
    public const TYPES = ['products', 'customers', 'suppliers'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TabularImporter $importer,
        private readonly VariantCatalog $catalog,
        private readonly CustomerDirectory $customers,
        private readonly SupplierDirectory $suppliers,
    ) {}

    /** @return list<ImportField> */
    public static function fields(string $type): array
    {
        return match ($type) {
            'products' => [
                new ImportField('reference', 'Référence', ImportField::TEXT, ['ref', 'référence', 'code', 'sku'], true),
                new ImportField('name', 'Désignation', ImportField::TEXT, ['nom', 'designation', 'désignation', 'libellé', 'libelle', 'produit'], true),
                new ImportField('pieces_per_carton', 'Pièces par carton', ImportField::INTEGER, ['pcs/ctn', 'colisage', 'pieces par carton']),
            ],
            'customers' => [
                new ImportField('name', 'Nom', ImportField::TEXT, ['nom', 'client', 'raison sociale', 'nom du client'], true),
                new ImportField('phone', 'Téléphone', ImportField::TEXT, ['tel', 'tél', 'telephone', 'téléphone', 'mobile']),
                new ImportField('email', 'E-mail', ImportField::TEXT, ['email', 'mail', 'courriel']),
                new ImportField('address', 'Adresse', ImportField::TEXT, ['adresse']),
                new ImportField('code', 'Code', ImportField::TEXT, ['code client']),
            ],
            'suppliers' => [
                new ImportField('code', 'Code', ImportField::TEXT, ['code fournisseur'], true),
                new ImportField('name', 'Nom', ImportField::TEXT, ['nom', 'fournisseur', 'raison sociale'], true),
                new ImportField('currency', 'Devise', ImportField::TEXT, ['monnaie'], true),
                new ImportField('phone', 'Téléphone', ImportField::TEXT, ['tel', 'téléphone']),
                new ImportField('email', 'E-mail', ImportField::TEXT, ['email', 'mail']),
            ],
            default => throw BusinessRuleViolation::invalidField('type', 'Type d\'import inconnu.'),
        };
    }

    /** @return array{type: string, simulated: bool, rows: int, created: int, errors: list<array{row: int, field: string, message: string}>} */
    public function run(string $type, string $path, string $format, bool $simulate, ?string $actorId): array
    {
        $read = $this->importer->read($path, $format, self::fields($type));
        $errors = [];

        foreach ($read['unmapped_required'] as $field) {
            $errors[] = ['row' => 0, 'field' => $field, 'message' => 'Colonne obligatoire introuvable.'];
        }

        foreach ($read['rows'] as $row) {
            foreach ($row->errors as $e) {
                $errors[] = ['row' => $row->rowNumber, 'field' => $e['field'], 'message' => $e['message']];
            }
        }

        $created = 0;
        $report = function () use ($type, $simulate, $read, &$created, &$errors): array {
            return ['type' => $type, 'simulated' => $simulate, 'rows' => count($read['rows']), 'created' => $created, 'errors' => $errors];
        };

        if ($errors !== []) {
            return $report();
        }

        try {
            $this->db->transaction(function () use ($type, $read, $simulate, $actorId, &$created, &$errors) {
                foreach ($read['rows'] as $row) {
                    $v = array_filter($row->values, fn ($x) => $x !== null);

                    try {
                        match ($type) {
                            'products' => $this->catalog->createStockProduct((string) $v['reference'], (string) $v['name'], isset($v['pieces_per_carton']) ? (int) $v['pieces_per_carton'] : null, $actorId),
                            'customers' => $this->customers->create($v, $actorId),
                            'suppliers' => $this->suppliers->create(['currency' => strtoupper((string) $v['currency'])] + $v, $actorId),
                            default => null,
                        };
                        $created++;
                    } catch (BusinessRuleViolation $e) {
                        $errors[] = ['row' => $row->rowNumber, 'field' => ltrim((string) ($e->errors[0]['pointer'] ?? ''), '/'), 'message' => $e->getMessage()];
                    }
                }

                if ($errors !== [] || $simulate) {
                    throw new RuntimeException('rollback');
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'rollback') {
                throw $e;
            }

            $created = $errors === [] ? $created : 0;
        }

        return $report();
    }
}
