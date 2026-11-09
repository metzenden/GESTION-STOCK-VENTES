<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use Illuminate\Http\Request;

/** Règles communes aux lignes de devis et de commande. */
final class SalesRequest
{
    /** @return array<string, list<string>> */
    public static function lineRules(bool $required, string $prefix = 'lines'): array
    {
        return [
            $prefix => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            "{$prefix}.*.variant_id" => ['required', 'string', 'size:26'],
            "{$prefix}.*.unit_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "{$prefix}.*.qty_units" => ['required', 'integer', 'min:1', 'max:10000000'],
            "{$prefix}.*.unit_price" => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            "{$prefix}.*.discount_type" => ['sometimes', 'nullable', 'in:percent,amount'],
            "{$prefix}.*.discount_value" => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,6})?$/'],
            "{$prefix}.*.tax_rate_id" => ['sometimes', 'nullable', 'string', 'size:26'],
            "{$prefix}.*.description" => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function discountRules(): array
    {
        return [
            'global_discount' => ['sometimes', 'nullable', 'array'],
            'global_discount.type' => ['required_with:global_discount', 'in:percent,amount'],
            'global_discount.value' => ['required_with:global_discount', 'string', 'regex:/^\d{1,15}(\.\d{1,6})?$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    public static function lines(array $validated, string $key = 'lines'): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $validated[$key] ?? [];

        return array_map(fn (array $l) => array_filter($l, fn ($v) => $v !== null), $lines);
    }

    public static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
