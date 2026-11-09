<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Sales\Domain\Services\SalesPricing;
use App\Modules\Settings\Contracts\TaxRates;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Money\CurrencyRegistry;
use Brick\Math\RoundingMode;
use DateTimeInterface;

/**
 * Prépare les lignes d'un devis ou d'une commande : article, conditionnement (facteur figé, CAT-007),
 * prix proposé par la grille (modifiable), remise, taxe choisie parmi les taux de l'entreprise, puis totaux (SAL-007).
 */
final class LineBuilder
{
    public function __construct(
        private readonly VariantCatalog $catalog,
        private readonly TaxRates $taxes,
        private readonly CurrencyRegistry $currencies,
        private readonly SalesPricing $pricing = new SalesPricing,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $input
     * @param  array{type: string, value: string}|null  $globalDiscount
     * @return array{lines: list<array<string, mixed>>, totals: array{subtotal: string, discount_total: string, tax_total: string, total: string}}
     */
    public function build(array $input, string $priceListId, string $currency, DateTimeInterface $date, ?array $globalDiscount): array
    {
        if ($input === []) {
            throw BusinessRuleViolation::invalidField('lines', 'Le document ne contient aucune ligne.');
        }

        $variants = $this->catalog->describe(array_values(array_unique(array_map(fn (array $l) => (string) $l['variant_id'], $input))));
        $units = $this->catalog->units(array_values(array_filter(array_map(fn (array $l) => isset($l['unit_id']) ? (string) $l['unit_id'] : null, $input))));
        $lines = [];

        foreach ($input as $index => $line) {
            $pointer = "lines/{$index}";
            $variant = $variants[(string) $line['variant_id']] ?? throw BusinessRuleViolation::invalidField("{$pointer}/variant_id", 'Cette variante n\'existe pas.');

            if ($variant->productStatus !== 'active' || $variant->variantStatus !== 'active') {
                throw BusinessRuleViolation::invalidField("{$pointer}/variant_id", 'Cet article est archivé ou inactif.');
            }

            $unit = isset($line['unit_id']) ? ($units[(string) $line['unit_id']] ?? null) : $this->catalog->baseUnit($variant->id);

            if ($unit === null || $unit->variantId !== $variant->id) {
                throw BusinessRuleViolation::invalidField("{$pointer}/unit_id", 'Ce conditionnement n\'appartient pas à l\'article.');
            }

            $qty = (int) $line['qty_units'];

            if ($qty <= 0) {
                throw BusinessRuleViolation::invalidField("{$pointer}/qty_units", 'La quantité doit être positive.');
            }

            $quote = $this->catalog->quote($variant->id, $unit->id, $priceListId, $date);
            $override = isset($line['unit_price']) ? (string) $line['unit_price'] : null;

            if ($quote === null && $override === null) {
                throw BusinessRuleViolation::invalidField("{$pointer}/unit_price", "Aucun prix n'est défini pour {$variant->label} ({$unit->label}) : saisissez le prix.", 'price_missing');
            }

            $listPrice = (string) Decimal::of($quote['price'] ?? $override ?? '0')->toScale(4, RoundingMode::HalfUp);
            $price = $override !== null ? (string) Decimal::of($override)->toScale(4, RoundingMode::HalfUp) : $listPrice;
            $taxRate = '0';
            $taxRateId = null;

            if (isset($line['tax_rate_id'])) {
                $tax = $this->taxes->find((string) $line['tax_rate_id']);

                if ($tax === null || ! $tax['is_active']) {
                    throw BusinessRuleViolation::invalidField("{$pointer}/tax_rate_id", 'Ce taux de taxe n\'existe pas ou est inactif.');
                }

                [$taxRate, $taxRateId] = [$tax['rate'], $tax['id']];
            }

            $lines[] = [
                'variant_id' => $variant->id,
                'product_type' => $variant->productType,
                'unit_id' => $unit->id,
                'unit_factor' => $unit->factor,
                'unit_label' => $unit->label,
                'description' => mb_substr(isset($line['description']) && $line['description'] !== '' ? (string) $line['description'] : self::label($variant->productName, $variant->label), 0, 255),
                'qty_units' => $qty,
                'qty_base' => $qty * $unit->factor,
                'list_unit_price' => $listPrice,
                'unit_price' => $price,
                'discount_type' => isset($line['discount_type']) ? (string) $line['discount_type'] : null,
                'discount_value' => isset($line['discount_value']) ? (string) $line['discount_value'] : null,
                'tax_rate_id' => $taxRateId,
                'tax_rate' => $taxRate,
            ];
        }

        $decimals = $this->currencies->of($currency)->decimals;
        $result = $this->pricing->compute(array_map(fn (array $l) => [
            'qty_units' => $l['qty_units'], 'unit_price' => $l['unit_price'], 'discount_type' => $l['discount_type'],
            'discount_value' => $l['discount_value'], 'tax_rate' => $l['tax_rate'],
        ], $lines), $globalDiscount, $decimals);

        foreach ($lines as $i => $line) {
            $lines[$i] += [
                'discount_amount' => $result['lines'][$i]['discount_amount'],
                'tax_amount' => $result['lines'][$i]['tax_amount'],
                'line_total' => $result['lines'][$i]['line_total'],
            ];
        }

        return ['lines' => $lines, 'totals' => ['subtotal' => $result['subtotal'], 'discount_total' => $result['discount_total'], 'tax_total' => $result['tax_total'], 'total' => $result['total']]];
    }

    private static function label(string $product, string $variant): string
    {
        return $variant === $product || $variant === '' ? $product : "{$product} — {$variant}";
    }
}
