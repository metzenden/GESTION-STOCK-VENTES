<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Infrastructure\Models\CompanySetting;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET/PATCH /settings/company : identité de l'entreprise et paramètres (L1-14, CDC §1.4). */
final class CompanySettingsController
{
    private const IDENTITY_RULES = [
        'trade_name' => ['sometimes', 'string', 'min:2', 'max:160'],
        'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
        'ninea' => ['sometimes', 'nullable', 'string', 'max:32'],
        'rccm' => ['sometimes', 'nullable', 'string', 'max:64'],
        'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        'city' => ['sometimes', 'nullable', 'string', 'max:100'],
        'country_code' => ['sometimes', 'string', 'size:2', 'alpha:ascii'],
        'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
    ];

    private const SETTINGS_RULES = [
        'default_payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        'low_stock_default_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        'invoice_legal_mentions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        'invoice_footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
        'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'allow_self_approval' => ['sometimes', 'boolean'],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->payload()]);
    }

    public function update(Request $request, ConnectionInterface $db): JsonResponse
    {
        $validated = $request->validate([
            'identity' => ['sometimes', 'array'],
            ...collect(self::IDENTITY_RULES)->mapWithKeys(fn ($rules, $field) => ["identity.{$field}" => $rules])->all(),
            'settings' => ['sometimes', 'array'],
            ...collect(self::SETTINGS_RULES)->mapWithKeys(fn ($rules, $field) => ["settings.{$field}" => $rules])->all(),
        ]);

        $companyId = $this->tenant->requireCompanyId();

        $db->transaction(function () use ($validated, $companyId, $request) {
            if (isset($validated['identity'])) {
                $this->companies->updateIdentity($companyId, $validated['identity']);
            }

            if (isset($validated['settings'])) {
                $settings = CompanySetting::query()->findOrFail($companyId);
                $settings->fill(array_intersect_key($validated['settings'], array_flip(CompanySetting::EDITABLE)));
                $settings->forceFill(['updated_by' => $request->user()?->getAuthIdentifier()])->save();
            }
        });

        return new JsonResponse(['data' => $this->payload()]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $companyId = $this->tenant->requireCompanyId();
        $settings = CompanySetting::query()->findOrFail($companyId);

        return [
            'identity' => $this->companies->identity($companyId),
            'settings' => [
                'default_payment_terms_days' => $settings->default_payment_terms_days,
                'rounding_mode' => $settings->rounding_mode,
                'low_stock_default_threshold' => $settings->low_stock_default_threshold,
                'invoice_legal_mentions' => $settings->invoice_legal_mentions,
                'invoice_footer' => $settings->invoice_footer,
                'payment_instructions' => $settings->payment_instructions,
                'start_date' => $settings->start_date?->format('Y-m-d'),
                'allow_self_approval' => $settings->allow_self_approval,
            ],
        ];
    }
}
