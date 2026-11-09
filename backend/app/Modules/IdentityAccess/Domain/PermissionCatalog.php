<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;

/**
 * Catalogue des permissions `module.action` et modèles de rôles par défaut (docs/04 §2, PV-17).
 *
 * Les permissions `platform.*` sont réservées au super-administrateur : elles ne peuvent
 * figurer dans aucun rôle d'entreprise (ADM-004).
 */
final class PermissionCatalog
{
    public const PLATFORM_PREFIX = 'platform.';

    /** @var array<string, array{module: string, label: string, sensitive?: bool}> */
    private const PERMISSIONS = [
        'users.view' => ['module' => 'users', 'label' => 'Consulter les utilisateurs'],
        'users.manage' => ['module' => 'users', 'label' => 'Inviter, modifier, désactiver les utilisateurs'],
        'roles.view' => ['module' => 'roles', 'label' => 'Consulter les rôles'],
        'roles.manage' => ['module' => 'roles', 'label' => 'Gérer les rôles'],
        'settings.view' => ['module' => 'settings', 'label' => 'Consulter les paramètres'],
        'settings.manage' => ['module' => 'settings', 'label' => 'Modifier les paramètres'],
        'products.view' => ['module' => 'products', 'label' => 'Consulter les produits'],
        'products.create' => ['module' => 'products', 'label' => 'Créer des produits'],
        'products.update' => ['module' => 'products', 'label' => 'Modifier des produits'],
        'products.view_sensitive' => ['module' => 'products', 'label' => 'Voir les coûts des produits', 'sensitive' => true],
        'stock.view' => ['module' => 'stock', 'label' => 'Consulter les stocks'],
        'inventories.create' => ['module' => 'stock', 'label' => 'Créer des inventaires'],
        'adjustments.create' => ['module' => 'stock', 'label' => 'Créer des ajustements de stock'],
        'transfers.manage' => ['module' => 'stock', 'label' => 'Gérer les transferts entre dépôts'],
        'warehouses.manage' => ['module' => 'stock', 'label' => 'Créer et modifier les dépôts'],
        'suppliers.view' => ['module' => 'suppliers', 'label' => 'Consulter les fournisseurs', 'sensitive' => true],
        'suppliers.manage' => ['module' => 'suppliers', 'label' => 'Gérer les fournisseurs', 'sensitive' => true],
        'purchase_orders.view' => ['module' => 'purchasing', 'label' => 'Consulter les commandes d\'achat', 'sensitive' => true],
        'purchase_orders.manage' => ['module' => 'purchasing', 'label' => 'Gérer les commandes d\'achat', 'sensitive' => true],
        'shipments.view' => ['module' => 'logistics', 'label' => 'Consulter les arrivages et réceptions'],
        'shipments.manage' => ['module' => 'logistics', 'label' => 'Gérer les arrivages, packing lists et réceptions'],
        'goods_receipts.accept_surplus' => ['module' => 'logistics', 'label' => 'Accepter un surplus en réception'],
        'costing.view' => ['module' => 'costing', 'label' => 'Consulter les coûts de revient', 'sensitive' => true],
        'costing.manage' => ['module' => 'costing', 'label' => 'Calculer et valider les coûts de revient', 'sensitive' => true],
        'customers.view' => ['module' => 'customers', 'label' => 'Consulter les clients'],
        'customers.manage' => ['module' => 'customers', 'label' => 'Gérer les clients'],
        'sales.create' => ['module' => 'sales', 'label' => 'Créer des devis et commandes'],
        'sales.confirm' => ['module' => 'sales', 'label' => 'Confirmer des devis et commandes'],
        'deliveries.prepare' => ['module' => 'deliveries', 'label' => 'Préparer les livraisons'],
        'deliveries.ship' => ['module' => 'deliveries', 'label' => 'Expédier les livraisons'],
        'deliveries.handover' => ['module' => 'deliveries', 'label' => 'Remettre les marchandises'],
        'invoices.view' => ['module' => 'billing', 'label' => 'Consulter les factures'],
        'invoices.print' => ['module' => 'billing', 'label' => 'Imprimer les factures'],
        'customer_payments.create' => ['module' => 'payments', 'label' => 'Enregistrer les paiements clients'],
        'customer_payments.reverse' => ['module' => 'payments', 'label' => 'Contre-passer un paiement ou changer de moyen de paiement'],
        'returns.manage' => ['module' => 'sales', 'label' => 'Réceptionner et contrôler les retours clients'],
        'credit_notes.create' => ['module' => 'billing', 'label' => 'Créer des avoirs'],
        'expenses.manage' => ['module' => 'finance', 'label' => 'Gérer les dépenses'],
        'financial_accounts.view_sensitive' => ['module' => 'finance', 'label' => 'Voir les soldes des comptes', 'sensitive' => true],
        'financial_accounts.manage' => ['module' => 'finance', 'label' => 'Créer et modifier les comptes financiers', 'sensitive' => true],
        'supplier_payments.manage' => ['module' => 'finance', 'label' => 'Gérer les paiements fournisseurs', 'sensitive' => true],
        'reports.financial' => ['module' => 'reports', 'label' => 'Rapports financiers et marges', 'sensitive' => true],
        'approvals.approve' => ['module' => 'approvals', 'label' => 'Approuver les demandes'],
        'audit.view' => ['module' => 'audit', 'label' => 'Consulter le journal d\'audit'],
        'platform.companies.view' => ['module' => 'platform', 'label' => 'Consulter les entreprises'],
        'platform.companies.manage' => ['module' => 'platform', 'label' => 'Créer, suspendre, réactiver les entreprises'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /** @return array<string, array{module: string, label: string, sensitive?: bool}> */
    public static function definitions(): array
    {
        return self::PERMISSIONS;
    }

    public static function exists(string $name): bool
    {
        return isset(self::PERMISSIONS[$name]);
    }

    public static function isPlatform(string $name): bool
    {
        return str_starts_with($name, self::PLATFORM_PREFIX);
    }

    /**
     * Toutes les permissions attribuables dans une entreprise.
     *
     * @return list<string>
     */
    public static function companyPermissions(): array
    {
        return array_values(array_filter(self::names(), fn (string $n) => ! self::isPlatform($n)));
    }

    /** @return list<string> */
    public static function forTemplate(RoleTemplate $template): array
    {
        return match ($template) {
            RoleTemplate::CompanyAdmin => self::companyPermissions(),
            RoleTemplate::Seller => [
                'products.view', 'stock.view', 'customers.view', 'customers.manage', 'sales.create', 'sales.confirm',
                'deliveries.handover', 'invoices.view', 'invoices.print', 'customer_payments.create', 'returns.manage',
            ],
            RoleTemplate::Storekeeper => [
                'products.view', 'products.create', 'products.update', 'stock.view', 'inventories.create',
                'adjustments.create', 'transfers.manage', 'suppliers.view', 'purchase_orders.view', 'shipments.view',
                'shipments.manage', 'deliveries.prepare', 'deliveries.ship', 'deliveries.handover', 'returns.manage',
            ],
            RoleTemplate::Accountant => [
                'products.view', 'products.view_sensitive', 'stock.view', 'suppliers.view', 'purchase_orders.view',
                'shipments.view', 'costing.view', 'costing.manage', 'customers.view', 'invoices.view', 'invoices.print',
                'customer_payments.create', 'customer_payments.reverse', 'credit_notes.create', 'expenses.manage', 'financial_accounts.view_sensitive',
                'supplier_payments.manage', 'reports.financial', 'audit.view',
            ],
        };
    }
}
