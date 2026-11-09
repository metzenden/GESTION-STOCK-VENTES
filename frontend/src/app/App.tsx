import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { ApiError } from '@/api/client';
import { ThemeProvider } from '@/lib/theme';
import { AppLayout } from '@/app/AppLayout';
import { NotFoundPage } from '@/app/NotFoundPage';
import { RolesPage } from '@/modules/access/pages/RolesPage';
import { UsersPage } from '@/modules/access/pages/UsersPage';
import { CompaniesPage } from '@/modules/admin/pages/CompaniesPage';
import { CompanyDetailPage } from '@/modules/admin/pages/CompanyDetailPage';
import { AuditPage } from '@/modules/audit/pages/AuditPage';
import { AcceptInvitationPage } from '@/modules/auth/pages/AcceptInvitationPage';
import { ForgotPasswordPage } from '@/modules/auth/pages/ForgotPasswordPage';
import { LoginPage } from '@/modules/auth/pages/LoginPage';
import { ResetPasswordPage } from '@/modules/auth/pages/ResetPasswordPage';
import { RequireAuth, RequireCompany, RequirePermission } from '@/modules/auth/RequireAuth';
import { HomePage } from '@/modules/dashboard/pages/HomePage';
import { SettingsPage } from '@/modules/settings/pages/SettingsPage';
import { CatalogSettingsPage } from '@/modules/catalog/pages/CatalogSettingsPage';
import { ProductDetailPage } from '@/modules/catalog/pages/ProductDetailPage';
import { ProductsPage } from '@/modules/catalog/pages/ProductsPage';
import { AdjustmentDetailPage, AdjustmentsPage } from '@/modules/stock/pages/AdjustmentsPage';
import { InventoriesPage, InventoryDetailPage } from '@/modules/stock/pages/InventoriesPage';
import { StockPage } from '@/modules/stock/pages/StockPage';
import { TransferDetailPage, TransfersPage } from '@/modules/stock/pages/TransfersPage';
import { WarehousesPage } from '@/modules/stock/pages/WarehousesPage';
import { CounterSalePage } from '@/modules/sales/pages/CounterSalePage';
import { ApprovalsPage } from '@/modules/operations/pages/ApprovalsPage';
import { ReportsPage } from '@/modules/operations/pages/ReportsPage';
import { TreasuryPage } from '@/modules/operations/pages/TreasuryPage';
import { CustomersPage } from '@/modules/sales/pages/CustomersPage';
import { InvoiceDetailPage, InvoicesPage } from '@/modules/sales/pages/InvoicesPage';
import { SalesOrderDetailPage, SalesOrdersPage } from '@/modules/sales/pages/SalesOrdersPage';
import { GoodsReceiptPage } from '@/modules/logistics/pages/GoodsReceiptPage';
import { LandedCostPage } from '@/modules/logistics/pages/LandedCostPage';
import { PackingListPage } from '@/modules/logistics/pages/PackingListPage';
import { ShipmentDetailPage, ShipmentsPage } from '@/modules/logistics/pages/ShipmentsPage';
import { PurchaseOrderDetailPage, PurchaseOrdersPage } from '@/modules/purchasing/pages/PurchaseOrdersPage';
import { SupplierStatementPage, SuppliersPage } from '@/modules/purchasing/pages/SuppliersPage';

export const routes = [
  { path: '/connexion', element: <LoginPage /> },
  { path: '/mot-de-passe-oublie', element: <ForgotPasswordPage /> },
  { path: '/reinitialiser-mot-de-passe', element: <ResetPasswordPage /> },
  { path: '/invitation/:token', element: <AcceptInvitationPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <HomePage /> },
          {
            element: <RequireCompany />,
            children: [
              {
                element: <RequirePermission permission="users.view" />,
                children: [{ path: 'utilisateurs', element: <UsersPage /> }],
              },
              {
                element: <RequirePermission permission="roles.view" />,
                children: [{ path: 'roles', element: <RolesPage /> }],
              },
              {
                element: <RequirePermission permission="settings.view" />,
                children: [{ path: 'parametres', element: <SettingsPage /> }],
              },
              {
                element: <RequirePermission permission="audit.view" />,
                children: [{ path: 'audit', element: <AuditPage /> }],
              },
              {
                element: <RequirePermission permission="products.view" />,
                children: [
                  { path: 'produits', element: <ProductsPage /> },
                  { path: 'produits/:id', element: <ProductDetailPage /> },
                  { path: 'catalogue', element: <CatalogSettingsPage /> },
                ],
              },
              {
                element: <RequirePermission permission="stock.view" />,
                children: [
                  { path: 'stock', element: <StockPage /> },
                  { path: 'stock/depots', element: <WarehousesPage /> },
                  { path: 'stock/ajustements', element: <AdjustmentsPage /> },
                  { path: 'stock/ajustements/:id', element: <AdjustmentDetailPage /> },
                  { path: 'stock/inventaires', element: <InventoriesPage /> },
                  { path: 'stock/inventaires/:id', element: <InventoryDetailPage /> },
                  { path: 'stock/transferts', element: <TransfersPage /> },
                  { path: 'stock/transferts/:id', element: <TransferDetailPage /> },
                ],
              },
              {
                element: <RequirePermission permission="suppliers.view" />,
                children: [{ path: 'achats/fournisseurs', element: <SuppliersPage /> }],
              },
              {
                element: <RequirePermission permission="supplier_payments.manage" />,
                children: [{ path: 'achats/fournisseurs/:id', element: <SupplierStatementPage /> }],
              },
              {
                element: <RequirePermission permission="purchase_orders.view" />,
                children: [
                  { path: 'achats/commandes', element: <PurchaseOrdersPage /> },
                  { path: 'achats/commandes/:id', element: <PurchaseOrderDetailPage /> },
                ],
              },
              {
                element: <RequirePermission permission="shipments.view" />,
                children: [
                  { path: 'arrivages', element: <ShipmentsPage /> },
                  { path: 'arrivages/:id', element: <ShipmentDetailPage /> },
                  { path: 'packing-lists/:id', element: <PackingListPage /> },
                  { path: 'receptions/:id', element: <GoodsReceiptPage /> },
                ],
              },
              { path: 'approbations', element: <ApprovalsPage /> },
              { path: 'approbations/:id', element: <ApprovalsPage /> },
              { path: 'rapports', element: <ReportsPage /> },
              {
                element: <RequirePermission permission="financial_accounts.view_sensitive" />,
                children: [{ path: 'tresorerie', element: <TreasuryPage /> }],
              },
              {
                element: <RequirePermission permission="customers.view" />,
                children: [{ path: 'clients', element: <CustomersPage /> }],
              },
              {
                element: <RequirePermission permission="sales.confirm" />,
                children: [{ path: 'comptoir', element: <CounterSalePage /> }],
              },
              {
                element: <RequirePermission permission="sales.create" />,
                children: [
                  { path: 'commandes', element: <SalesOrdersPage /> },
                  { path: 'commandes/:id', element: <SalesOrderDetailPage /> },
                ],
              },
              {
                element: <RequirePermission permission="invoices.view" />,
                children: [
                  { path: 'factures', element: <InvoicesPage /> },
                  { path: 'factures/:id', element: <InvoiceDetailPage /> },
                  { path: 'creances', element: <InvoicesPage receivables /> },
                ],
              },
              {
                element: <RequirePermission permission="costing.view" />,
                children: [{ path: 'couts/:id', element: <LandedCostPage /> }],
              },
            ],
          },
          {
            element: <RequirePermission superAdmin />,
            children: [
              { path: 'admin/entreprises', element: <CompaniesPage /> },
              { path: 'admin/entreprises/:id', element: <CompanyDetailPage /> },
            ],
          },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
];

function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        // Inutile de réessayer une erreur métier ou d'autorisation : seules les erreurs réseau/5xx sont rejouées.
        retry: (failureCount, error) =>
          !(error instanceof ApiError && error.status < 500) && failureCount < 2,
      },
    },
  });
}

export function App() {
  const [queryClient] = useState(createQueryClient);
  const [router] = useState(() => createBrowserRouter(routes));

  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </ThemeProvider>
  );
}
