<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Contracts\CompanyPreferences;
use App\Modules\Settings\Infrastructure\Models\CompanySetting;
use App\Modules\Shared\Contracts\TenantContext;

final class EloquentCompanyPreferences implements CompanyPreferences
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function defaultSalesWarehouseId(): ?string
    {
        $value = $this->settings()->getAttribute('default_sales_warehouse_id');

        return is_string($value) ? $value : null;
    }

    public function setDefaultSalesWarehouse(?string $warehouseId): void
    {
        $settings = $this->settings();
        $settings->forceFill(['default_sales_warehouse_id' => $warehouseId])->save();
    }

    public function lowStockDefaultThreshold(): int
    {
        return $this->settings()->low_stock_default_threshold;
    }

    public function defaultPaymentTermsDays(): int
    {
        return (int) $this->settings()->getAttribute('default_payment_terms_days');
    }

    public function documentTexts(): array
    {
        $s = $this->settings();
        $text = fn (string $key) => is_string($s->getAttribute($key)) && $s->getAttribute($key) !== '' ? (string) $s->getAttribute($key) : null;

        return ['legal_mentions' => $text('invoice_legal_mentions'), 'footer' => $text('invoice_footer'), 'payment_instructions' => $text('payment_instructions')];
    }

    public function allowsSelfApproval(): bool
    {
        return (bool) $this->settings()->getAttribute('allow_self_approval');
    }

    private function settings(): CompanySetting
    {
        return CompanySetting::query()->findOrFail($this->tenant->requireCompanyId());
    }
}
