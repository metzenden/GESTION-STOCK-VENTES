<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Imports\Contracts\ImportField;
use App\Modules\Imports\Contracts\MappingTemplates;
use App\Modules\Imports\Contracts\TabularImporter;
use App\Modules\Logistics\Infrastructure\Models\PackingList;
use App\Modules\Logistics\Infrastructure\Models\PackingListLine;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;

/**
 * Packing lists Excel/CSV (L3-06) : téléversement → extraction → revue ligne à ligne → validation.
 * L'import ne touche jamais le stock (IMP-001) ; la validation alimente les articles attendus, chaque ligne
 * étant rattachée à une variante existante ou à un produit créé explicitement (IMP-006).
 */
final class PackingListService
{
    public const IMPORT_TYPE = 'packing_list';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly PrivateFileStorage $files,
        private readonly TabularImporter $importer,
        private readonly MappingTemplates $templates,
        private readonly VariantCatalog $catalog,
        private readonly SupplierDirectory $suppliers,
        private readonly ShipmentService $shipments,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return list<ImportField> */
    public static function fields(): array
    {
        return [
            new ImportField('supplier_reference', 'Référence fournisseur', ImportField::TEXT, ['ref', 'reference', 'référence', 'item', 'item no', 'item number', 'article', 'code', 'sku', 'model', 'modele', 'style']),
            new ImportField('description', 'Désignation', ImportField::TEXT, ['designation', 'désignation', 'libelle', 'libellé', 'product', 'product name', 'goods', 'description of goods', 'nom']),
            new ImportField('cartons', 'Nombre de cartons', ImportField::INTEGER, ['ctns', 'ctn', 'cartons', 'nb cartons', 'colis', 'packages', 'pkgs', 'qty ctns']),
            new ImportField('pieces_per_carton', 'Pièces par carton', ImportField::INTEGER, ['pcs/ctn', 'pcs ctn', 'qty/ctn', 'pcs per carton', 'pieces par carton', 'pcs/carton', 'colisage', 'pcs/box', 'inner']),
            new ImportField('total_qty', 'Quantité totale (pièces)', ImportField::INTEGER, ['qty', 'quantity', 'quantite', 'quantité', 'total pcs', 'total qty', 'pcs', 'pieces', 'total quantity']),
            new ImportField('cbm', 'Volume total (m³)', ImportField::DECIMAL, ['cbm', 'volume', 'm3', 'cbm total', 'total cbm', 'meas', 'measurement']),
            new ImportField('cbm_per_carton', 'Volume par carton (m³)', ImportField::DECIMAL, ['cbm/ctn', 'cbm per carton', 'cbm/carton', 'volume carton', 'unit cbm']),
            new ImportField('unit_price', 'Prix unitaire', ImportField::DECIMAL, ['unit price', 'prix unitaire', 'price', 'pu', 'u price', 'unit cost', 'prix']),
            new ImportField('amount', 'Montant', ImportField::DECIMAL, ['amount', 'montant', 'total', 'total amount', 'value', 'total price']),
        ];
    }

    /** @param array{header_row?: int, columns?: array<string, string>}|null $mapping */
    public function upload(string $shipmentId, UploadedFile $file, ?string $supplierId, ?string $currency, ?string $templateId, ?array $mapping, ?string $actorId): PackingList
    {
        return $this->db->transaction(function () use ($shipmentId, $file, $supplierId, $currency, $templateId, $mapping, $actorId) {
            $shipment = $this->editable($shipmentId, 'importer une packing list');
            $supplier = $supplierId !== null ? ($this->suppliers->find($supplierId) ?? throw BusinessRuleViolation::invalidField('supplier_id', 'Ce fournisseur n\'existe pas.')) : null;
            $stored = $this->files->storeUpload($file, FilePurpose::PackingList);
            $format = match (true) {
                str_contains($stored->mime_type, 'spreadsheetml') => 'xlsx',
                $stored->mime_type === 'application/pdf' => 'pdf',
                default => 'csv',
            };

            if ($format === 'pdf') {
                // L3-07 : extraction PDF en attente des exemples réels de fournisseurs (PV-09, D-091).
                throw new BusinessRuleViolation('pdf_not_supported', 'PDF non pris en charge',
                    'L\'extraction des packing lists PDF n\'est pas encore disponible : importez le fichier Excel ou CSV du fournisseur.');
            }

            $template = $templateId !== null ? $this->templates->find($templateId) : $this->templates->latestFor(self::IMPORT_TYPE, $supplier?->id, $format);
            /** @var array{header_row?: int, columns?: array<string, string>}|null $effective */
            $effective = $mapping ?? ($template['mapping'] ?? null);

            $list = new PackingList;
            $list->fill([
                'shipment_id' => $shipment->id,
                'supplier_id' => $supplier?->id,
                'file_id' => $stored->id,
                'mapping_template_id' => $template['id'] ?? null,
                'file_format' => $format,
                'currency' => $currency ?? $supplier->currency ?? $this->referenceCurrency(),
                'status' => 'uploaded',
            ])->forceFill(['created_by' => $actorId])->save();

            $this->parse($list, $effective);

            if ($mapping !== null && $supplier !== null) {
                $this->templates->remember(self::IMPORT_TYPE, $supplier->id, "Packing list {$supplier->code}", $format, $mapping, $actorId);
            }

            $this->audit->record('packing_list.uploaded', 'packing_list', $list->id, [], ['shipment_id' => $shipment->id, 'rows' => $list->lines()->count()]);

            return $list->refresh();
        });
    }

    /**
     * Revue d'une ligne : rattachement, création explicite de produit, correction ou mise à l'écart.
     *
     * @param  array<string, mixed>  $data
     */
    public function reviewLine(string $listId, string $lineId, array $data, ?string $actorId): PackingListLine
    {
        return $this->db->transaction(function () use ($listId, $lineId, $data, $actorId) {
            $list = $this->lockInReview($listId);
            $line = PackingListLine::query()->where('packing_list_id', $list->id)->lockForUpdate()->findOrFail($lineId);

            foreach (['description', 'cartons', 'pieces_per_carton', 'total_qty', 'cbm', 'unit_price'] as $field) {
                if (array_key_exists($field, $data)) {
                    $line->setAttribute($field, $data[$field]);
                }
            }

            match ($data['action'] ?? null) {
                'confirm' => $line->forceFill([
                    'matched_variant_id' => $this->stockedVariant((string) ($data['variant_id'] ?? $line->matched_variant_id ?? '')),
                    'match_status' => 'confirmed',
                ]),
                'new_product' => $line->forceFill([
                    'matched_variant_id' => $this->catalog->createStockProduct(
                        (string) ($data['new_product']['reference'] ?? $line->supplier_reference ?? throw BusinessRuleViolation::invalidField('new_product/reference', 'Indiquez la référence du nouveau produit.', 'required')),
                        (string) ($data['new_product']['name'] ?? $line->description ?? $line->supplier_reference),
                        $line->pieces_per_carton, $actorId),
                    'match_status' => 'new_product',
                ]),
                'ignore' => $line->forceFill(['match_status' => 'ignored']),
                'reset' => $line->forceFill(['match_status' => $line->matched_variant_id !== null ? 'suggested' : 'unmatched']),
                default => null,
            };

            $line->forceFill(['errors' => $this->lineErrors($line), 'updated_by' => $actorId])->save();

            return $line->refresh();
        });
    }

    /** IMP-006 : toutes les lignes résolues ; alimente ou met à jour les articles attendus de l'arrivage. */
    public function validate(string $listId, ?string $actorId): PackingList
    {
        return $this->db->transaction(function () use ($listId, $actorId) {
            $list = $this->lockInReview($listId);
            $shipment = $this->editable($list->shipment_id, 'valider la packing list');
            $lines = $list->lines()->get();
            $kept = $lines->filter(fn (PackingListLine $l) => $l->match_status !== 'ignored');

            foreach ($lines as $index => $line) {
                if (! $line->isResolved()) {
                    throw new BusinessRuleViolation('packing_list_unresolved', 'Revue incomplète',
                        "Ligne {$line->row_number} : rattachez-la à un article, créez le produit ou écartez-la.", 422, [],
                        [['pointer' => "/lines/{$index}", 'code' => 'unresolved', 'message' => 'Ligne non rattachée.']]);
                }

                $firstError = $line->errors[0]['message'] ?? null;

                if ($line->match_status !== 'ignored' && $firstError !== null) {
                    throw new BusinessRuleViolation('packing_list_invalid', 'Ligne invalide', "Ligne {$line->row_number} : {$firstError}", 422, [],
                        [['pointer' => "/lines/{$index}", 'code' => 'invalid', 'message' => $firstError]]);
                }
            }

            if ($kept->isEmpty()) {
                throw new BusinessRuleViolation('packing_list_empty', 'Aucune ligne retenue', 'Toutes les lignes ont été écartées.');
            }

            $existing = ShipmentLine::query()->where('shipment_id', $shipment->id)->whereNull('packing_list_line_id')->get()->groupBy('variant_id');
            $new = [];

            foreach ($kept as $line) {
                $fields = [
                    'qty_expected' => $line->total_qty,
                    'cartons' => $line->cartons,
                    'pieces_per_carton' => $line->pieces_per_carton,
                    'cbm_total' => $line->cbm,
                ];
                $target = $existing->get((string) $line->matched_variant_id)?->shift();

                if ($target !== null) {
                    // Ligne de commande déjà attendue : la packing list en donne le détail réellement expédié.
                    $updated = $this->shipments->updateLine($target->id, array_filter($fields, fn ($v) => $v !== null), $actorId);
                    $updated->forceFill(['packing_list_line_id' => $line->id])->save();

                    continue;
                }

                $new[] = $fields + [
                    'variant_id' => (string) $line->matched_variant_id,
                    'supplier_id' => $list->supplier_id,
                    'purchase_currency' => $list->currency,
                    'purchase_unit_price' => $this->unitPrice($line),
                    'packing_list_line_id' => $line->id,
                ];
            }

            if ($new !== []) {
                $created = $this->shipments->addLines($shipment->id, array_map(fn (array $f) => array_diff_key($f, ['packing_list_line_id' => true]), $new), $actorId);

                foreach ($created as $i => $shipmentLine) {
                    $shipmentLine->forceFill(['packing_list_line_id' => $new[$i]['packing_list_line_id']])->save();
                }
            }

            $list->forceFill(['status' => 'validated', 'validated_at' => CarbonImmutable::now(), 'validated_by' => $actorId, 'version' => $list->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('packing_list.validated', 'packing_list', $list->id, ['status' => 'in_review'], ['status' => 'validated', 'lines' => $kept->count()]);

            return $list->refresh();
        });
    }

    public function reject(string $listId, ?string $actorId): PackingList
    {
        return $this->db->transaction(function () use ($listId, $actorId) {
            $list = $this->lockInReview($listId);
            $list->forceFill(['status' => 'rejected', 'version' => $list->version + 1, 'updated_by' => $actorId])->save();
            $this->audit->record('packing_list.rejected', 'packing_list', $list->id, ['status' => 'in_review'], ['status' => 'rejected']);

            return $list->refresh();
        });
    }

    /** @param array{header_row?: int, columns?: array<string, string>}|null $mapping */
    private function parse(PackingList $list, ?array $mapping): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pl');

        try {
            file_put_contents($tmp, $this->files->contents($this->files->findForCurrentCompany($list->file_id)));
            $result = $this->importer->read($tmp, $list->file_format, self::fields(), $mapping);
        } finally {
            @unlink($tmp);
        }

        if ($result['rows'] === []) {
            throw BusinessRuleViolation::invalidField('file', 'Aucune ligne exploitable n\'a été trouvée dans le fichier.');
        }

        $suggested = 0;

        foreach ($result['rows'] as $row) {
            $v = $row->values;
            $cartons = is_int($v['cartons']) ? $v['cartons'] : null;
            $ppc = is_int($v['pieces_per_carton']) ? $v['pieces_per_carton'] : null;
            $qty = is_int($v['total_qty']) ? $v['total_qty'] : ($cartons !== null && $ppc !== null ? $cartons * $ppc : null);
            // CR-009 : le volume retenu est celui de la ligne ; un CBM par carton est converti explicitement.
            $cbm = is_string($v['cbm']) ? $v['cbm'] : (is_string($v['cbm_per_carton']) && $cartons !== null
                ? (string) Decimal::of($v['cbm_per_carton'])->multipliedBy($cartons)->toScale(6, RoundingMode::HalfUp) : null);
            $reference = is_string($v['supplier_reference']) ? mb_substr($v['supplier_reference'], 0, 64) : null;
            $match = $reference !== null ? $this->catalog->matchReference($reference) : null;
            $suggested += $match !== null ? 1 : 0;

            $line = new PackingListLine;
            $line->fill([
                'packing_list_id' => $list->id,
                'row_number' => $row->rowNumber,
                'raw' => $row->raw,
                'supplier_reference' => $reference,
                'description' => is_string($v['description']) ? $v['description'] : null,
                'cartons' => $cartons,
                'pieces_per_carton' => $ppc,
                'total_qty' => $qty !== null && $qty > 0 ? $qty : null,
                'cbm' => $cbm !== null && Decimal::of($cbm)->isPositive() ? $cbm : null,
                'unit_price' => is_string($v['unit_price']) ? $v['unit_price'] : null,
                'amount' => is_string($v['amount']) ? (string) Decimal::of($v['amount'])->toScale(4, RoundingMode::HalfUp) : null,
                'matched_variant_id' => $match,
                'match_status' => $match !== null ? 'suggested' : 'unmatched',
            ]);
            $line->forceFill(['errors' => array_merge($row->errors, $this->lineErrors($line))])->save();
        }

        $list->forceFill(['status' => 'in_review', 'parse_report' => [
            'headers' => $result['headers'],
            'columns' => $result['columns'],
            'unmapped_required' => $result['unmapped_required'],
            'rows' => count($result['rows']),
            'suggested' => $suggested,
        ]])->save();
    }

    /** @return list<array{field: string, message: string}> */
    private function lineErrors(PackingListLine $line): array
    {
        $errors = [];

        if ($line->total_qty === null || $line->total_qty <= 0) {
            $errors[] = ['field' => 'total_qty', 'message' => 'Quantité totale manquante (ou cartons × pièces par carton).'];
        }

        if ($line->cartons !== null && $line->pieces_per_carton !== null && $line->total_qty !== null && $line->cartons * $line->pieces_per_carton !== $line->total_qty) {
            $errors[] = ['field' => 'total_qty', 'message' => "La quantité ({$line->total_qty}) ne correspond pas à cartons × pièces par carton."];
        }

        return $errors;
    }

    private function unitPrice(PackingListLine $line): string
    {
        if ($line->unit_price !== null) {
            return $line->unit_price;
        }

        if ($line->amount !== null && $line->total_qty !== null && $line->total_qty > 0) {
            return (string) Decimal::of($line->amount)->dividedBy($line->total_qty, 8, RoundingMode::HalfUp);
        }

        return '0';
    }

    private function stockedVariant(string $variantId): string
    {
        $variant = $this->catalog->describe([$variantId])[$variantId] ?? null;

        if ($variant === null || ! $variant->isStocked()) {
            throw BusinessRuleViolation::invalidField('variant_id', 'Choisissez une variante stockée existante.');
        }

        return $variant->id;
    }

    private function editable(string $shipmentId, string $action): Shipment
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);

        if (! in_array($shipment->status, ShipmentService::EDITABLE, true)) {
            throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, $action);
        }

        return $shipment;
    }

    private function lockInReview(string $listId): PackingList
    {
        $list = PackingList::query()->lockForUpdate()->findOrFail($listId);

        if ($list->status !== 'in_review') {
            throw BusinessRuleViolation::invalidTransition('la packing list', $list->status, 'modifier');
        }

        return $list;
    }

    private function referenceCurrency(): string
    {
        return $this->companies->find($this->tenant->requireCompanyId())->referenceCurrency ?? 'XOF';
    }
}
