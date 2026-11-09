<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\PriceService;
use App\Modules\Catalog\Http\Resources\CatalogPresenter;
use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Money\CurrencyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Grilles tarifaires et prix proposé (CAT-004). */
final class PricingController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => PriceList::query()->orderByDesc('is_default')->orderBy('name')->get()->map(CatalogPresenter::priceList(...))->values()]);
    }

    public function store(Request $request, CurrencyRegistry $currencies): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{1,16}$/'],
            'name' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'exists:currencies,code'],
        ]);

        if (PriceList::query()->where('code', $validated['code'])->exists()) {
            throw BusinessRuleViolation::invalidField('code', 'Ce code de grille existe déjà.');
        }

        $currencies->of($validated['currency']);
        $list = new PriceList($validated + ['is_default' => false]);
        $list->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => CatalogPresenter::priceList($list)], 201);
    }

    public function update(Request $request, string $priceList): JsonResponse
    {
        $list = PriceList::query()->findOrFail($priceList);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'is_default' => ['sometimes', 'accepted'],
        ]);

        if (isset($validated['is_default'])) {
            PriceList::query()->whereKeyNot($list->id)->where('is_default', true)->get()->each(fn (PriceList $l) => $l->forceFill(['is_default' => false])->save());
            $list->is_default = true;
        }

        if (isset($validated['name'])) {
            $list->name = $validated['name'];
        }

        $list->forceFill(['updated_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => CatalogPresenter::priceList($list)]);
    }

    public function quote(Request $request, PriceService $prices): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'string', 'size:26'],
            'unit_id' => ['required', 'string', 'size:26'],
            'price_list_id' => ['sometimes', 'string', 'size:26'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $variant = ProductVariant::query()->findOrFail((string) $validated['variant_id']);
        $quote = $prices->quote($variant->id, $validated['unit_id'], $validated['price_list_id'] ?? null, CarbonImmutable::parse($validated['date'] ?? 'today'));

        if ($quote === null) {
            throw new BusinessRuleViolation('price_not_found', 'Aucun prix', 'Aucun prix n\'est défini pour cette variante, cette grille et ce conditionnement à cette date.', 404);
        }

        return new JsonResponse(['data' => [
            'price' => $quote['price']->rounded()->jsonSerialize(),
            'source' => $quote['source'],
            'price_list_id' => $quote['price_list_id'],
            'unit_id' => $quote['unit_id'],
            'factor' => $quote['factor'],
        ]]);
    }
}
