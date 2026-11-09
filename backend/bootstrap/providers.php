<?php

declare(strict_types=1);

use App\Modules\Approvals\ApprovalsServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Costing\CostingServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\IdentityAccess\IdentityAccessServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Logistics\LogisticsServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Settings\SettingsServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Modules (docs/02 §2.1) — Shared en premier : macros de schéma et contrats communs.
    SharedServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
    IdentityAccessServiceProvider::class,
    SettingsServiceProvider::class,
    ApprovalsServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    ProcurementServiceProvider::class,
    ImportsServiceProvider::class,
    LogisticsServiceProvider::class,
    CostingServiceProvider::class,
    FinanceServiceProvider::class,
    SalesServiceProvider::class,
    BillingServiceProvider::class,
    PaymentsServiceProvider::class,
    ReportingServiceProvider::class,
];
