<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\ExchangeRate;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Taux de change datés, saisis par l'entreprise (CDC §15.4). Un taux enregistré n'est pas modifié : on en saisit un nouveau. */
final class ExchangeRateController
{
    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'filter.from_currency' => ['sometimes', 'string', 'size:3'],
            'filter.to_currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $query = ExchangeRate::query()->orderByDesc('valid_on')->orderBy('from_currency');

        foreach (['from_currency', 'to_currency'] as $column) {
            if (isset($validated['filter'][$column])) {
                $query->where($column, $validated['filter'][$column]);
            }
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), self::present(...));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_currency' => ['required', 'string', Rule::exists('currencies', 'code')],
            'to_currency' => ['required', 'string', 'different:from_currency', Rule::exists('currencies', 'code')],
            'rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,10})?$/'],
            'valid_on' => ['required', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (! Decimal::of($validated['rate'])->isPositive()) {
            throw ValidationException::withMessages(['rate' => 'Le taux doit être strictement positif.']);
        }

        $exists = ExchangeRate::query()->where([
            'from_currency' => $validated['from_currency'],
            'to_currency' => $validated['to_currency'],
            'valid_on' => $validated['valid_on'],
        ])->exists();

        if ($exists) {
            throw ValidationException::withMessages(['valid_on' => 'Un taux existe déjà pour ces devises à cette date.']);
        }

        $rate = new ExchangeRate($validated);
        $rate->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => self::present($rate->refresh())], 201);
    }

    /** @return array<string, mixed> */
    public static function present(ExchangeRate $rate): array
    {
        return [
            'id' => $rate->id,
            'from_currency' => $rate->from_currency,
            'to_currency' => $rate->to_currency,
            'rate' => (string) Decimal::of($rate->rate)->strippedOfTrailingZeros(),
            'valid_on' => $rate->valid_on->format('Y-m-d'),
            'source' => $rate->source,
        ];
    }
}
