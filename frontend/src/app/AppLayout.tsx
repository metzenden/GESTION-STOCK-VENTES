import { LogOut, Menu, X } from 'lucide-react';
import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router';
import { ThemeToggle } from '@/components/ThemeToggle';
import { Alert } from '@/components/ui/Alert';
import { cn } from '@/lib/cn';
import { CompanySwitcher } from '@/modules/admin/CompanySwitcher';
import { HeaderTools } from '@/modules/operations/components/HeaderTools';
import { hasPermission, useLogout, useMe, type Me } from '@/modules/auth/session';

interface NavItem {
  to: string;
  label: string;
  visible: (me: Me) => boolean;
}

const withCompany = (permission: string) => (me: Me) => me.company !== null && hasPermission(me, permission);

const navigation: NavItem[] = [
  { to: '/', label: 'Tableau de bord', visible: () => true },
  { to: '/comptoir', label: 'Vente comptoir', visible: withCompany('sales.confirm') },
  { to: '/commandes', label: 'Commandes clients', visible: withCompany('sales.create') },
  { to: '/factures', label: 'Factures', visible: withCompany('invoices.view') },
  { to: '/creances', label: 'Créances', visible: withCompany('invoices.view') },
  { to: '/clients', label: 'Clients', visible: withCompany('customers.view') },
  { to: '/tresorerie', label: 'Trésorerie', visible: withCompany('financial_accounts.view_sensitive') },
  { to: '/approbations', label: 'Approbations', visible: (me) => me.company !== null },
  { to: '/rapports', label: 'Rapports', visible: (me) => me.company !== null },
  { to: '/produits', label: 'Produits', visible: withCompany('products.view') },
  { to: '/stock', label: 'Stocks', visible: withCompany('stock.view') },
  { to: '/stock/inventaires', label: 'Inventaires', visible: withCompany('stock.view') },
  { to: '/stock/transferts', label: 'Transferts', visible: withCompany('stock.view') },
  { to: '/stock/ajustements', label: 'Ajustements', visible: withCompany('stock.view') },
  { to: '/stock/depots', label: 'Dépôts', visible: withCompany('stock.view') },
  { to: '/achats/commandes', label: 'Commandes d’achat', visible: withCompany('purchase_orders.view') },
  { to: '/arrivages', label: 'Arrivages et réceptions', visible: withCompany('shipments.view') },
  { to: '/achats/fournisseurs', label: 'Fournisseurs', visible: withCompany('suppliers.view') },
  { to: '/catalogue', label: 'Organisation du catalogue', visible: withCompany('products.view') },
  { to: '/utilisateurs', label: 'Utilisateurs', visible: withCompany('users.view') },
  { to: '/roles', label: 'Rôles et permissions', visible: withCompany('roles.view') },
  { to: '/parametres', label: 'Paramètres', visible: withCompany('settings.view') },
  { to: '/audit', label: "Journal d'audit", visible: withCompany('audit.view') },
  { to: '/admin/entreprises', label: 'Entreprises', visible: (me) => me.user.is_super_admin },
];

export function AppLayout() {
  const [menuOpen, setMenuOpen] = useState(false);
  const { data: me } = useMe();
  const logout = useLogout();
  const navigate = useNavigate();

  if (me === null || me === undefined) {
    return null;
  }

  const signOut = () =>
    logout.mutate(undefined, { onSettled: () => void navigate('/connexion', { replace: true }) });

  return (
    <div className="flex min-h-dvh">
      {/* Barre latérale : fixe sur ordinateur, tiroir sur smartphone */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-30 flex w-64 transform flex-col border-r border-slate-200 bg-white transition-transform dark:border-slate-800 dark:bg-slate-900 lg:static lg:translate-x-0',
          menuOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-label="Navigation principale"
      >
        <div className="flex h-14 items-center justify-between px-4">
          <span className="text-lg font-semibold text-brand-700 dark:text-brand-500">FDS Business</span>
          <button
            type="button"
            className="rounded p-2 lg:hidden"
            onClick={() => setMenuOpen(false)}
            aria-label="Fermer le menu"
          >
            <X className="size-5" />
          </button>
        </div>
        <nav className="flex-1 space-y-1 px-2">
          {navigation
            .filter((item) => item.visible(me))
            .map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/' || item.to === '/stock'}
                onClick={() => setMenuOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'block rounded-md px-3 py-2 text-sm font-medium',
                    isActive
                      ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100'
                      : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                  )
                }
              >
                {item.label}
              </NavLink>
            ))}
        </nav>
        <div className="border-t border-slate-200 p-3 text-sm dark:border-slate-800">
          <p className="truncate font-medium">{me.user.name}</p>
          <p className="truncate text-xs text-slate-500">{me.user.email}</p>
          <button
            type="button"
            onClick={signOut}
            className="mt-2 inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            <LogOut className="size-4" aria-hidden />
            Se déconnecter
          </button>
        </div>
      </aside>

      {menuOpen && (
        <div
          className="fixed inset-0 z-20 bg-slate-900/40 lg:hidden"
          onClick={() => setMenuOpen(false)}
          aria-hidden
        />
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 flex h-14 items-center gap-2 border-b border-slate-200 bg-white/90 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
          <button
            type="button"
            className="rounded p-2 lg:hidden"
            onClick={() => setMenuOpen(true)}
            aria-label="Ouvrir le menu"
          >
            <Menu className="size-5" />
          </button>
          {me.user.is_super_admin ? (
            <CompanySwitcher />
          ) : (
            <span className="truncate text-sm font-medium">{me.company?.trade_name}</span>
          )}
          <div className="flex-1" />
          {me.company !== null && <HeaderTools />}
          <ThemeToggle />
        </header>
        <main className="flex-1 p-4 sm:p-6">
          {me.company?.status === 'suspended' && (
            <div className="mb-4">
              <Alert tone="warning">
                Cette entreprise est suspendue : les données sont consultables mais aucune modification n'est
                possible.
              </Alert>
            </div>
          )}
          <Outlet />
        </main>
      </div>
    </div>
  );
}
