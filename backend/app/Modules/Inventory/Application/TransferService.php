<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Infrastructure\Models\StockTransfer;
use App\Modules\Inventory\Infrastructure\Models\StockTransferLine;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Transferts entre dépôts (L2-13, D-060) : brouillon → confirmé (réservation) → en transit (transfer_out)
 * → réceptionné (transfer_in, écart déclaré en perte) ; annulation en brouillon ou confirmé.
 * Le total de l'entreprise (dépôts + transit) ne varie que de la perte déclarée (TRF-005).
 */
final class TransferService
{
    public const LINE_SOURCE = 'transfer_line';

    public const SOURCE = 'transfer';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly StockLedger $ledger,
        private readonly VariantCatalog $catalog,
        private readonly AdjustmentService $adjustments,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<array{variant_id: string, quantity: int}> $lines */
    public function create(string $fromId, string $toId, array $lines, ?string $note, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($fromId, $toId, $lines, $note, $actorId) {
            $this->assertWarehouses($fromId, $toId);

            $transfer = new StockTransfer;
            $transfer->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::StockTransfer, $this->today()),
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'status' => 'draft',
                'note' => $note,
            ])->forceFill(['created_by' => $actorId])->save();

            $this->replaceLines($transfer, $lines);

            return $transfer->refresh();
        });
    }

    /** @param list<array{variant_id: string, quantity: int}>|null $lines */
    public function update(string $id, ?array $lines, ?string $note, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($id, $lines, $note, $actorId) {
            $transfer = $this->lock($id, ['draft'], 'modifier');

            if ($lines !== null) {
                $this->replaceLines($transfer, $lines);
            }

            $transfer->forceFill(['note' => $note ?? $transfer->note, 'version' => $transfer->version + 1, 'updated_by' => $actorId])->save();

            return $transfer;
        });
    }

    /** TRF-002 : réservation au dépôt source, complète ou refusée. */
    public function confirm(string $id, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $transfer = $this->lock($id, ['draft'], 'confirmer');
            $lines = $transfer->lines()->get()->sortBy('variant_id');

            foreach ($lines as $line) {
                $this->ledger->reserve($transfer->from_warehouse_id, $line->variant_id, $line->qty_requested, self::LINE_SOURCE, $line->id);
            }

            return $this->mark($transfer, 'confirmed', $actorId);
        }, 3);
    }

    /**
     * TRF-003 : affectation des lots (FIFO, ou choix manuel par ligne) et sortie transfer_out.
     *
     * @param  array<string, array<string, int>>  $manual  ligne → (lot → quantité)
     */
    public function ship(string $id, array $manual, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($id, $manual, $actorId) {
            $transfer = $this->lock($id, ['confirmed'], 'expédier');
            $now = CarbonImmutable::now();

            foreach ($transfer->lines()->get()->sortBy('variant_id') as $line) {
                $allocation = isset($manual[$line->id])
                    ? $manual[$line->id]
                    : $this->ledger->allocateFifo($transfer->from_warehouse_id, $line->variant_id, $line->qty_requested);

                if (array_sum($allocation) !== $line->qty_requested) {
                    throw BusinessRuleViolation::invalidField("allocations/{$line->id}", "L'affectation doit totaliser {$line->qty_requested}.");
                }

                // Une ligne par lot : la ligne d'origine porte le premier lot, les suivantes sont créées.
                $first = true;
                $perLine = [];

                foreach ($allocation as $lotId => $qty) {
                    if ($first) {
                        $line->forceFill(['lot_id' => $lotId, 'qty_requested' => $qty, 'qty_sent' => $qty])->save();
                        $perLine[$line->id] = [$lotId, $qty];
                        $first = false;

                        continue;
                    }

                    $split = (new StockTransferLine)->fill([
                        'transfer_id' => $transfer->id,
                        'variant_id' => $line->variant_id,
                        'lot_id' => $lotId,
                        'parent_line_id' => $line->id,
                        'qty_requested' => $qty,
                        'qty_sent' => $qty,
                    ]);
                    $split->save();
                    $perLine[$split->id] = [$lotId, $qty];
                }

                // La réservation est portée par la ligne d'origine : sortie de chaque lot avec l'origine de sa ligne.
                foreach ($perLine as $lineId => [$lotId, $qty]) {
                    $this->ledger->issueReserved(self::LINE_SOURCE, $line->id, [$lotId => $qty], MovementType::TransferOut->value,
                        new StockSource(self::SOURCE, $transfer->id, $lineId), $now);
                }
            }

            $transfer->forceFill(['shipped_at' => $now]);

            return $this->mark($transfer, 'in_transit', $actorId);
        }, 3);
    }

    /**
     * TRF-004 : entrée transfer_in au dépôt cible (même lot, même coût) ; tout écart est déclaré en perte.
     *
     * @param  array<string, int>  $received  ligne → quantité reçue (absente = quantité envoyée)
     */
    public function receive(string $id, array $received, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($id, $received, $actorId) {
            $transfer = $this->lock($id, ['in_transit'], 'réceptionner');
            $now = CarbonImmutable::now();
            $losses = [];

            foreach ($transfer->lines()->get() as $line) {
                $qty = $received[$line->id] ?? $line->qty_sent;

                if ($qty < 0 || $qty > $line->qty_sent) {
                    throw BusinessRuleViolation::invalidField("received/{$line->id}", "La quantité reçue doit être comprise entre 0 et {$line->qty_sent}.");
                }

                // L'intégralité envoyée entre au dépôt cible, puis l'écart en sort comme perte déclarée et traçable.
                $this->ledger->receive($transfer->to_warehouse_id, (string) $line->lot_id, $line->qty_sent, MovementType::TransferIn->value,
                    new StockSource(self::SOURCE, $transfer->id, $line->id), $now);
                $line->forceFill(['qty_received' => $qty])->save();

                if ($qty < $line->qty_sent) {
                    $losses[] = ['variant_id' => $line->variant_id, 'lot_id' => $line->lot_id, 'quantity' => $qty - $line->qty_sent, 'kind' => 'loss'];
                }
            }

            if ($losses !== []) {
                $adjustment = $this->adjustments->create($transfer->to_warehouse_id, "Écart de transfert {$transfer->number}", $losses, $actorId, transferId: $transfer->id);
                $this->adjustments->submit($adjustment->id, $actorId);
            }

            $transfer->forceFill(['received_at' => $now]);

            return $this->mark($transfer, 'received', $actorId);
        }, 3);
    }

    /** TRF-006 : annulation en brouillon, ou confirmé (libère la réservation). */
    public function cancel(string $id, ?string $actorId): StockTransfer
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $transfer = $this->lock($id, ['draft', 'confirmed'], 'annuler');

            if ($transfer->status === 'confirmed') {
                foreach ($transfer->lines()->get()->sortBy('variant_id') as $line) {
                    $this->ledger->release(self::LINE_SOURCE, $line->id);
                }
            }

            return $this->mark($transfer, 'cancelled', $actorId);
        }, 3);
    }

    /** @param list<array{variant_id: string, quantity: int}> $lines */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        if ($lines === []) {
            throw BusinessRuleViolation::invalidField('lines', 'Le transfert ne contient aucune ligne.');
        }

        $variants = $this->catalog->describe(array_values(array_unique(array_column($lines, 'variant_id'))));
        StockTransferLine::query()->where('transfer_id', $transfer->id)->delete();
        $seen = [];

        foreach ($lines as $index => $line) {
            $variant = $variants[$line['variant_id']] ?? throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Cette variante n\'existe pas.');

            if (! $variant->isStocked()) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Une prestation n\'a pas de stock.');
            }

            if (isset($seen[$variant->id])) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Cette variante figure déjà dans le transfert.');
            }

            if ($line['quantity'] <= 0) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/quantity", 'La quantité doit être positive.');
            }

            $seen[$variant->id] = true;
            (new StockTransferLine)->fill(['transfer_id' => $transfer->id, 'variant_id' => $variant->id, 'qty_requested' => $line['quantity']])->save();
        }
    }

    private function mark(StockTransfer $transfer, string $status, ?string $actorId): StockTransfer
    {
        $previous = $transfer->status;
        $transfer->forceFill(['status' => $status, 'version' => $transfer->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("stock_transfer.{$status}", 'stock_transfer', $transfer->id, ['status' => $previous], ['status' => $status, 'number' => $transfer->number]);

        return $transfer;
    }

    private function assertWarehouses(string $fromId, string $toId): void
    {
        if ($fromId === $toId) {
            throw BusinessRuleViolation::invalidField('to_warehouse_id', 'Les dépôts de départ et d\'arrivée doivent être différents.');
        }

        foreach (['from_warehouse_id' => $fromId, 'to_warehouse_id' => $toId] as $field => $id) {
            $warehouse = Warehouse::query()->find($id);

            if ($warehouse === null || $warehouse->status !== 'active') {
                throw BusinessRuleViolation::invalidField($field, 'Ce dépôt n\'existe pas ou est inactif.');
            }
        }
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): StockTransfer
    {
        $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($transfer->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('le transfert', $transfer->status, $action);
        }

        return $transfer;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
