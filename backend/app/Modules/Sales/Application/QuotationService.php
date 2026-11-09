<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Sales\Infrastructure\Models\Quotation;
use App\Modules\Sales\Infrastructure\Models\QuotationLine;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Devis (L4-02, docs/04 §8.1) : aucun effet sur le stock ni la trésorerie (QUO-001) ;
 * conversion unique en commande brouillon (QUO-002, QUO-003).
 */
final class QuotationService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly LineBuilder $builder,
        private readonly VariantCatalog $catalog,
        private readonly SalesOrderService $orders,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $header, array $lines, ?string $actorId): Quotation
    {
        return $this->db->transaction(function () use ($header, $lines, $actorId) {
            $customer = Customer::query()->where('status', 'active')->find((string) $header['customer_id'])
                ?? throw BusinessRuleViolation::invalidField('customer_id', 'Ce client n\'existe pas ou est inactif.');
            $list = $this->catalog->priceList(isset($header['price_list_id']) ? (string) $header['price_list_id'] : $customer->default_price_list_id)
                ?? throw BusinessRuleViolation::invalidField('price_list_id', 'Aucune grille tarifaire n\'est disponible.');
            $today = $this->orders->today();

            $quotation = new Quotation;
            $quotation->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Quotation, $today),
                'customer_id' => $customer->id,
                'price_list_id' => $list['id'],
                'warehouse_id' => $header['warehouse_id'] ?? null,
                'currency' => $list['currency'],
                'issue_date' => $today->toDateString(),
                'valid_until' => $header['valid_until'] ?? $today->addDays(30)->toDateString(),
                'global_discount_type' => $header['global_discount']['type'] ?? null,
                'global_discount_value' => $header['global_discount']['value'] ?? null,
                'notes' => $header['notes'] ?? null,
                'status' => 'draft',
            ])->forceFill(['created_by' => $actorId])->save();
            $this->replaceLines($quotation, $lines);

            return $quotation->refresh();
        });
    }

    /**
     * QUO-001 : modifiable tant qu'il n'est ni accepté ni converti ; un devis envoyé repasse en brouillon.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function update(string $id, array $header, ?array $lines, ?string $actorId): Quotation
    {
        return $this->db->transaction(function () use ($id, $header, $lines, $actorId) {
            $quotation = $this->lock($id, ['draft', 'sent'], 'modifier');

            foreach (['valid_until', 'notes', 'warehouse_id'] as $field) {
                if (array_key_exists($field, $header)) {
                    $quotation->setAttribute($field, $header[$field]);
                }
            }

            if (array_key_exists('global_discount', $header)) {
                $quotation->forceFill(['global_discount_type' => $header['global_discount']['type'] ?? null, 'global_discount_value' => $header['global_discount']['value'] ?? null]);
            }

            $quotation->forceFill(['status' => 'draft', 'version' => $quotation->version + 1, 'updated_by' => $actorId])->save();
            $this->replaceLines($quotation, $lines ?? self::linesOf($quotation));

            return $quotation->refresh();
        });
    }

    public function send(string $id, ?string $actorId): Quotation
    {
        return $this->transition($id, ['draft', 'sent'], 'sent', 'envoyer', $actorId);
    }

    public function accept(string $id, ?string $actorId): Quotation
    {
        return $this->transition($id, ['sent'], 'accepted', 'accepter', $actorId);
    }

    public function cancel(string $id, ?string $actorId): Quotation
    {
        return $this->transition($id, ['draft', 'sent'], 'cancelled', 'annuler', $actorId);
    }

    /**
     * QUO-002, QUO-003 : une seule conversion ; prix conservés si le devis est valide, sinon recalculés (avertissement).
     *
     * @return array{order_id: string, warnings: list<array<string, string>>}
     */
    public function convert(string $id, ?string $warehouseId, ?string $actorId): array
    {
        return $this->db->transaction(function () use ($id, $warehouseId, $actorId) {
            $quotation = $this->lock($id, ['draft', 'sent', 'accepted'], 'convertir');
            $valid = $quotation->valid_until === null || $quotation->valid_until->greaterThanOrEqualTo($this->orders->today()->startOfDay());
            $lines = self::linesOf($quotation);
            $warnings = [];

            if (! $valid) {
                $lines = array_map(fn (array $l) => array_diff_key($l, ['unit_price' => true]), $lines);
                $warnings[] = ['code' => 'prices_recalculated', 'message' => 'Le devis a expiré : les prix ont été recalculés selon la grille en vigueur.'];
            }

            $order = $this->orders->create([
                'customer_id' => $quotation->customer_id,
                'price_list_id' => $quotation->price_list_id,
                'warehouse_id' => $warehouseId ?? $quotation->warehouse_id,
                'quotation_id' => $quotation->id,
                'notes' => $quotation->notes,
                'global_discount' => $quotation->global_discount_type !== null ? ['type' => $quotation->global_discount_type, 'value' => (string) $quotation->global_discount_value] : null,
            ], $lines, $actorId);

            $quotation->forceFill(['converted_order_id' => $order->id]);
            $this->mark($quotation, 'converted', $actorId);

            return ['order_id' => $order->id, 'warnings' => $warnings];
        });
    }

    /** Tâche planifiée : les devis envoyés dont la validité est dépassée passent « expiré ». */
    public function expireOutdated(CarbonImmutable $today): int
    {
        $ids = Quotation::query()->where('status', 'sent')->whereNotNull('valid_until')->where('valid_until', '<', $today->toDateString())->pluck('id');

        foreach ($ids as $id) {
            $this->db->transaction(fn () => $this->transition((string) $id, ['sent'], 'expired', 'expirer', null));
        }

        return $ids->count();
    }

    /** @param list<array<string, mixed>> $input */
    private function replaceLines(Quotation $quotation, array $input): void
    {
        $global = $quotation->global_discount_type !== null ? ['type' => $quotation->global_discount_type, 'value' => (string) $quotation->global_discount_value] : null;
        $built = $this->builder->build($input, $quotation->price_list_id, $quotation->currency, $this->orders->today(), $global);
        QuotationLine::query()->where('quotation_id', $quotation->id)->delete();

        foreach ($built['lines'] as $index => $line) {
            (new QuotationLine)->fill($line + ['quotation_id' => $quotation->id, 'position' => $index + 1])->save();
        }

        $quotation->forceFill($built['totals'])->save();
    }

    /** @return list<array<string, mixed>> */
    private static function linesOf(Quotation $quotation): array
    {
        return array_values($quotation->lines()->get()->map(fn (QuotationLine $l) => [
            'variant_id' => $l->variant_id, 'unit_id' => $l->unit_id, 'qty_units' => $l->qty_units, 'unit_price' => $l->unit_price,
            'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value, 'tax_rate_id' => $l->tax_rate_id, 'description' => $l->description,
        ])->all());
    }

    /** @param list<string> $from */
    private function transition(string $id, array $from, string $to, string $action, ?string $actorId): Quotation
    {
        return $this->db->transaction(fn () => $this->mark($this->lock($id, $from, $action), $to, $actorId));
    }

    private function mark(Quotation $quotation, string $status, ?string $actorId): Quotation
    {
        $previous = $quotation->status;
        $quotation->forceFill(['status' => $status, 'version' => $quotation->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("quotation.{$status}", 'quotation', $quotation->id, ['status' => $previous], ['status' => $status, 'number' => $quotation->number]);

        return $quotation->refresh();
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): Quotation
    {
        $quotation = Quotation::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($quotation->status, $expected, true)) {
            if ($quotation->status === 'converted' && $action === 'convertir') {
                throw BusinessRuleViolation::alreadyProcessed('Ce devis a déjà été converti en commande.');
            }

            throw BusinessRuleViolation::invalidTransition('le devis', $quotation->status, $action);
        }

        return $quotation;
    }
}
