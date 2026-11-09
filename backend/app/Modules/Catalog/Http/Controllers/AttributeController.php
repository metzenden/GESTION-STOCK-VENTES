<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Attribute;
use App\Modules\Catalog\Infrastructure\Models\AttributeValue;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Attributs de variantes (Couleur, Taille…) et leurs valeurs. */
final class AttributeController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => Attribute::query()->with('values')->orderBy('name')->get()->map(self::present(...))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:64']]);

        if (Attribute::query()->where('name', $validated['name'])->exists()) {
            throw BusinessRuleViolation::invalidField('name', 'Cet attribut existe déjà.');
        }

        $attribute = new Attribute($validated);
        $attribute->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => self::present($attribute->load('values'))], 201);
    }

    public function storeValue(Request $request, string $attribute): JsonResponse
    {
        $parent = Attribute::query()->findOrFail($attribute);
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:64'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (AttributeValue::query()->where('attribute_id', $parent->id)->where('value', $validated['value'])->exists()) {
            throw BusinessRuleViolation::invalidField('value', 'Cette valeur existe déjà.');
        }

        $value = new AttributeValue(['attribute_id' => $parent->id] + $validated);
        $value->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => self::present($parent->load('values'))], 201);
    }

    /** @return array<string, mixed> */
    private static function present(Attribute $attribute): array
    {
        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'values' => $attribute->values->map(fn (AttributeValue $v) => ['id' => $v->id, 'value' => $v->value, 'position' => $v->position])->values()->all(),
        ];
    }
}
