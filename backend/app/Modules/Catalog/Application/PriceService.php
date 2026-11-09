<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\ProductPrice;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Money;
use App\Modules\Shared\Infrastructure\Money\CurrencyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Prix datés par (variante, grille, conditionnement) et prix proposé (L2-04, CAT-004, D-049).
 *
 * - Pas de chevauchement de périodes ; un nouveau prix clôt automatiquement le prix ouvert qui le précède.
 * - Prix proposé : prix en vigueur à la date ; à défaut pour un conditionnement, prix pièce × facteur.
 */
final class PriceService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CurrencyRegistry $currencies,
    ) {}

    public function add(string $variantId, string $priceListId, string $unitId, string $price, CarbonImmutable $validFrom, ?CarbonImmutable $validTo, ?string $actorId): ProductPrice
    {
        return $this->db->transaction(function () use ($variantId, $priceListId, $unitId, $price, $validFrom, $validTo, $actorId) {
            // Verrou de la variante : deux saisies concurrentes ne peuvent créer de chevauchement.
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);
            $list = PriceList::query()->find($priceListId) ?? throw BusinessRuleViolation::invalidField('price_list_id', 'Cette grille tarifaire n\'existe pas.');
            $unit = VariantUnit::query()->where('variant_id', $variant->id)->find($unitId)
                ?? throw BusinessRuleViolation::invalidField('unit_id', 'Ce conditionnement n\'appartient pas à la variante.');

            $amount = Money::of($price, $this->currencies->of($list->currency));

            if (! $amount->isRounded()) {
                throw BusinessRuleViolation::invalidField('price', "Le prix doit respecter la précision de la devise {$list->currency} ({$amount->currency()->decimals} décimale(s)).");
            }

            if ($validTo !== null && $validTo->lt($validFrom)) {
                throw BusinessRuleViolation::invalidField('valid_to', 'La fin de validité précède le début.');
            }

            $existing = ProductPrice::query()
                ->where(['variant_id' => $variant->id, 'price_list_id' => $list->id, 'unit_id' => $unit->id])
                ->orderBy('valid_from')->lockForUpdate()->get();

            foreach ($existing as $other) {
                // Prix ouvert antérieur : il prend fin la veille du nouveau prix.
                if ($other->valid_to === null && $other->valid_from->lt($validFrom)) {
                    $other->forceFill(['valid_to' => $validFrom->subDay(), 'updated_by' => $actorId])->save();

                    continue;
                }

                $otherEnd = $other->valid_to;
                $overlaps = ($validTo === null || $other->valid_from->lte($validTo)) && ($otherEnd === null || $otherEnd->gte($validFrom));

                if ($overlaps) {
                    throw BusinessRuleViolation::invalidField('valid_from', 'Cette période chevauche un prix existant du '
                        .$other->valid_from->format('d/m/Y').($otherEnd !== null ? ' au '.$otherEnd->format('d/m/Y') : '').'.', 'price_overlap');
                }
            }

            $row = new ProductPrice;
            $row->fill([
                'variant_id' => $variant->id,
                'price_list_id' => $list->id,
                'unit_id' => $unit->id,
                'price' => $amount->toStorage(),
                'valid_from' => $validFrom->toDateString(),
                'valid_to' => $validTo?->toDateString(),
            ])->forceFill(['created_by' => $actorId])->save();

            return $row;
        });
    }

    /**
     * @return array{price: Money, source: 'list'|'derived', price_list_id: string, unit_id: string, factor: int}|null
     */
    public function quote(string $variantId, string $unitId, ?string $priceListId, CarbonImmutable $date): ?array
    {
        $unit = VariantUnit::query()->where('variant_id', $variantId)->findOrFail($unitId);
        $list = $priceListId !== null
            ? PriceList::query()->findOrFail($priceListId)
            : PriceList::query()->where('is_default', true)->first();

        if ($list === null) {
            return null;
        }

        $currency = $this->currencies->of($list->currency);
        $direct = $this->priceAt($variantId, $list->id, $unit->id, $date);

        if ($direct !== null) {
            return ['price' => Money::of($direct, $currency), 'source' => 'list', 'price_list_id' => $list->id, 'unit_id' => $unit->id, 'factor' => $unit->factor];
        }

        if ($unit->factor > 1) {
            $base = VariantUnit::query()->where('variant_id', $variantId)->where('factor', 1)->orderByRaw("code = 'piece' DESC")->first();
            $basePrice = $base !== null ? $this->priceAt($variantId, $list->id, $base->id, $date) : null;

            if ($basePrice !== null) {
                return ['price' => Money::of($basePrice, $currency)->multipliedBy($unit->factor), 'source' => 'derived', 'price_list_id' => $list->id, 'unit_id' => $unit->id, 'factor' => $unit->factor];
            }
        }

        return null;
    }

    private function priceAt(string $variantId, string $listId, string $unitId, CarbonImmutable $date): ?string
    {
        $value = ProductPrice::query()
            ->where(['variant_id' => $variantId, 'price_list_id' => $listId, 'unit_id' => $unitId])
            ->where('valid_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date->toDateString()))
            ->orderByDesc('valid_from')
            ->value('price');

        return $value === null ? null : (string) $value;
    }
}
