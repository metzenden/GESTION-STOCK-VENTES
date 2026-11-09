<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Approvals\Infrastructure\Models\ApprovalRule;
use App\Modules\Catalog\Application\CategoryService;
use App\Modules\Catalog\Application\PriceService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Infrastructure\Models\PriceList;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Procurement\Application\PurchaseOrderService;
use App\Modules\Procurement\Application\SupplierService;
use App\Modules\Sales\Application\CustomerService;
use App\Modules\Sales\Application\DeliveryService;
use App\Modules\Sales\Application\SalesOrderService;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de démonstration pour le développement local uniquement (make fresh).
 * Tous les comptes utilisent le mot de passe DEMO_PASSWORD ci-dessous.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo-FDS-2026-local';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Les données de démonstration sont interdites en production.');
        }

        $this->user(null, 'Super Administrateur', 'superadmin@fds.local', superAdmin: true);

        $dakar = Company::factory()->provisioned()->create(['code' => 'FDS', 'trade_name' => 'FDS Dakar', 'city' => 'Dakar']);
        $thies = Company::factory()->provisioned()->create(['code' => 'THS', 'trade_name' => 'Comptoir de Thiès', 'city' => 'Thiès']);

        $this->user($dakar, 'Aminata Sow', 'admin@fds.local', RoleTemplate::CompanyAdmin);
        $this->user($dakar, 'Moussa Diallo', 'vendeur@fds.local', RoleTemplate::Seller);
        $this->user($dakar, 'Fatou Ndiaye', 'magasinier@fds.local', RoleTemplate::Storekeeper);
        $this->user($dakar, 'Ibrahima Fall', 'comptable@fds.local', RoleTemplate::Accountant);
        $this->user($thies, 'Ousmane Ba', 'admin@thies.local', RoleTemplate::CompanyAdmin);

        $this->catalog($dakar);
        $this->purchasing($dakar);
        $this->sales($dakar);
    }

    /** Quelques articles avec stock d'ouverture, prix et un second dépôt. */
    private function catalog(Company $company): void
    {
        $admin = User::query()->where('email', 'admin@fds.local')->firstOrFail();
        auth()->guard('web')->setUser($admin);
        $tenant = app(TenantContext::class);

        $tenant->runAs($company->id, function () use ($company) {
            $products = app(ProductService::class);
            $prices = app(PriceService::class);
            $ledger = app(StockLedger::class);
            $warehouse = (string) DB::table('company_settings')->where('company_id', $company->id)->value('default_sales_warehouse_id');
            $retail = PriceList::query()->where('code', 'retail')->value('id');
            (new Warehouse(['code' => 'THIES', 'name' => 'Dépôt de Thiès', 'status' => 'active']))->save();

            $textile = app(CategoryService::class)->create('Textile', null, null);
            $kitchen = app(CategoryService::class)->create('Cuisine', null, null);

            $items = [
                ['TSH-COT', 'T-shirt coton', $textile->id, 12, '1750', 120, '2500'],
                ['PAG-WAX', 'Pagne wax 6 yards', $textile->id, 10, '6500', 45, '9000'],
                ['MAR-INOX', 'Marmite inox 10 L', $kitchen->id, 4, '9800', 8, '14500'],
                ['THE-VERT', 'Thé vert (paquet)', $kitchen->id, 24, '450', 300, '700'],
            ];

            foreach ($items as [$reference, $name, $category, $perCarton, $cost, $qty, $price]) {
                DB::transaction(function () use ($products, $prices, $ledger, $warehouse, $retail, $reference, $name, $category, $perCarton, $cost, $qty, $price) {
                    $product = $products->create(['reference' => $reference, 'name' => $name, 'category_id' => $category, 'pieces_per_carton' => $perCarton, 'low_stock_threshold' => 10], null);
                    $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
                    $piece = VariantUnit::query()->where('variant_id', $variant->id)->where('code', 'piece')->value('id');
                    $prices->add($variant->id, (string) $retail, (string) $piece, $price, CarbonImmutable::parse('2026-01-01'), null, null);

                    $at = CarbonImmutable::now()->subDays(30);
                    $lot = $ledger->createLot($variant->id, 'opening', null, $at, $qty, $cost, 'XOF');
                    $line = strtoupper((string) Str::ulid());
                    $ledger->receive($warehouse, $lot, $qty, 'opening', new StockSource('opening', $line, $line), $at);
                });
            }
        });
    }

    /** Fournisseurs, une commande confirmée en USD et un arrivage en route qui la reprend (lot 3). */
    private function purchasing(Company $company): void
    {
        app(TenantContext::class)->runAs($company->id, function () use ($company) {
            $admin = User::query()->where('email', 'admin@fds.local')->value('id');
            $suppliers = app(SupplierService::class);
            $factory = $suppliers->create(['code' => 'GZ-TEX', 'name' => 'Guangzhou Textile Co.', 'supplier_type' => 'goods', 'currency' => 'USD', 'country_code' => 'CN'], $admin);
            $suppliers->create(['code' => 'TRANSIT-DKR', 'name' => 'Transit Dakar Port (démo)', 'supplier_type' => 'forwarder', 'currency' => 'XOF', 'country_code' => 'SN'], $admin);

            $variants = ProductVariant::query()->whereIn('sku', ['TSH-COT', 'PAG-WAX'])->pluck('id', 'sku');
            $orders = app(PurchaseOrderService::class);
            // Taux estimé de démonstration : il sert au coût provisoire, jamais de taux officiel inventé.
            $order = $orders->create(['supplier_id' => $factory->id, 'estimated_rate' => '600', 'supplier_reference' => 'PI-2026-118'], [
                ['variant_id' => (string) $variants['TSH-COT'], 'qty_units' => 240, 'unit_price' => '1.85'],
                ['variant_id' => (string) $variants['PAG-WAX'], 'qty_units' => 100, 'unit_price' => '7.20'],
            ], $admin);
            $orders->confirm($order->id, $admin);

            $warehouse = (string) DB::table('company_settings')->where('company_id', $company->id)->value('default_sales_warehouse_id');
            $shipments = app(ShipmentService::class);
            $shipment = $shipments->create(['reference' => 'MSKU-DEMO-01', 'carrier' => 'Maersk', 'eta' => CarbonImmutable::now()->addDays(12)->toDateString(), 'default_warehouse_id' => $warehouse], $admin);
            $shipments->addContainer($shipment->id, ['container_number' => 'MSKU1234565', 'container_type' => '20GP'], $admin);
            $shipments->linkPurchaseOrder($shipment->id, $order->id, true, $admin);

            foreach ($shipment->lines()->get() as $index => $line) {
                $shipments->updateLine($line->id, ['cbm_total' => $index === 0 ? '3.2' : '1.8'], $admin);
            }

            $shipments->transition($shipment->id, 'shipped', null, $admin);
            $shipments->transition($shipment->id, 'in_transit', null, $admin);
        });
    }

    /** Clients, une vente livrée et réglée par Wave, une vente à crédit, une règle d'approbation (lots 4 à 6). */
    private function sales(Company $company): void
    {
        app(TenantContext::class)->runAs($company->id, function () {
            $admin = User::query()->where('email', 'admin@fds.local')->value('id');
            $customers = app(CustomerService::class);
            $awa = $customers->create(['name' => 'Awa Diop', 'phone' => '771234567', 'payment_terms_days' => 15], $admin);
            $customers->create(['name' => 'Boutique Ndiaye & Fils', 'customer_type' => 'business', 'phone' => '338221100'], $admin);
            $variants = ProductVariant::query()->whereIn('sku', ['TSH-COT', 'THE-VERT'])->pluck('id', 'sku');
            $orders = app(SalesOrderService::class);
            $deliveries = app(DeliveryService::class);
            $accounts = DB::table('financial_accounts')->where('company_id', app(TenantContext::class)->requireCompanyId())->pluck('id', 'account_type');

            $paid = $orders->create(['customer_id' => $awa->id], [['variant_id' => (string) $variants['TSH-COT'], 'qty_units' => 6], ['variant_id' => (string) $variants['THE-VERT'], 'qty_units' => 10]], $admin);
            $paid = $orders->submit($paid->id, $admin)['order'];
            $delivery = $deliveries->create($paid->id, null, [], $admin);
            $deliveries->ready($delivery->id, $admin);
            $deliveries->deliver($delivery->id, [], [], $admin);
            app(PaymentService::class)->create((string) $paid->invoice_id, 'wave', (string) $accounts['wave'], $paid->total, CarbonImmutable::now(), 'WV-DEMO-1', $admin);

            $credit = $orders->create(['customer_id' => $awa->id], [['variant_id' => (string) $variants['TSH-COT'], 'qty_units' => 12]], $admin);
            $orders->submit($credit->id, $admin);

            (new ApprovalRule)->fill(['name' => 'Remise supérieure à 15 %', 'operation' => 'discount', 'threshold_type' => 'percent', 'threshold_value' => '15', 'priority' => 100, 'is_active' => true])->save();
        });
    }

    private function user(?Company $company, string $name, string $email, ?RoleTemplate $template = null, bool $superAdmin = false): void
    {
        $user = new User;
        $user->forceFill([
            'company_id' => $company?->id,
            'is_super_admin' => $superAdmin,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => self::DEMO_PASSWORD,
            'status' => UserStatus::Active,
        ])->save();

        if ($company !== null && $template !== null) {
            $role = Role::query()->where('company_id', $company->id)->where('template_code', $template->value)->firstOrFail();
            app(TenantContext::class)->runAs($company->id, fn () => $user->assignRole($role));
        }
    }
}
