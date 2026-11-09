<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\TaxRate;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Taux de taxe de l'entreprise : liste vide par défaut (M-01). Les taux et leurs règles d'application
 * relèvent de la vérification réglementaire (VR-01) ; l'application ne les invente pas.
 */
final class TaxRateController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => TaxRate::query()->orderBy('name')->get()->map(self::present(...))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:64', Rule::unique('tax_rates', 'name')->where('company_id', $this->companyId())],
            'rate' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertRange($validated['rate']);

        $tax = new TaxRate($validated);
        $tax->forceFill(['created_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => self::present($tax->refresh())], 201);
    }

    public function update(Request $request, string $taxRate): JsonResponse
    {
        $tax = TaxRate::query()->findOrFail($taxRate);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:64', Rule::unique('tax_rates', 'name')->where('company_id', $this->companyId())->ignore($tax->id)],
            'rate' => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['rate'])) {
            $this->assertRange($validated['rate']);
        }

        $tax->fill($validated)->forceFill(['updated_by' => $request->user()?->getAuthIdentifier()])->save();

        return new JsonResponse(['data' => self::present($tax->refresh())]);
    }

    /** @return array<string, mixed> */
    public static function present(TaxRate $tax): array
    {
        return [
            'id' => $tax->id,
            'name' => $tax->name,
            'rate' => (string) Decimal::of($tax->rate)->strippedOfTrailingZeros(),
            'is_active' => $tax->is_active,
        ];
    }

    private function assertRange(string $rate): void
    {
        if (Decimal::of($rate)->isGreaterThan(100)) {
            throw ValidationException::withMessages(['rate' => 'Le taux doit être compris entre 0 et 100 %.']);
        }
    }

    private function companyId(): string
    {
        return app(TenantContext::class)->requireCompanyId();
    }
}
