import { Navigate, Outlet, useLocation } from 'react-router';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { hasPermission, useMe } from '@/modules/auth/session';

/** Routes réservées aux utilisateurs connectés ; redirige vers la connexion en conservant la destination. */
export function RequireAuth() {
  const location = useLocation();
  const me = useMe();

  if (me.isPending) {
    return <Spinner />;
  }

  if (me.isError) {
    return (
      <div className="mx-auto max-w-md p-6">
        <ErrorAlert error={me.error} />
      </div>
    );
  }

  if (me.data === null) {
    const redirect = encodeURIComponent(location.pathname + location.search);
    return <Navigate to={`/connexion?redirection=${redirect}`} replace />;
  }

  return <Outlet />;
}

/** Contenu réservé à une permission ; sinon message explicite (l'API refuse de toute façon, en 403). */
export function RequirePermission({
  permission,
  superAdmin = false,
}: {
  permission?: string;
  superAdmin?: boolean;
}) {
  const { data: me } = useMe();
  const allowed = superAdmin
    ? me?.user.is_super_admin === true
    : permission === undefined || hasPermission(me, permission);

  if (!allowed) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <h1 className="text-xl font-semibold">Accès refusé</h1>
        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
          Vous n'avez pas la permission d'afficher cette page.
        </p>
      </div>
    );
  }

  return <Outlet />;
}

/** Pages métier : exigent une entreprise active (le super-admin doit d'abord en choisir une). */
export function RequireCompany() {
  const { data: me } = useMe();

  if (me?.company === null || me?.company === undefined) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <h1 className="text-xl font-semibold">Choisissez une entreprise</h1>
        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
          Sélectionnez l'entreprise à consulter dans le sélecteur en haut de l'écran.
        </p>
      </div>
    );
  }

  return <Outlet />;
}
