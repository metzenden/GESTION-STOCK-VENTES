import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { Card } from '@/components/ui/Card';
import { useMe } from '@/modules/auth/session';
import { DashboardPanels } from '@/modules/operations/components/DashboardPanels';

export function HomePage() {
  const { data: me } = useMe();
  const health = useQuery({
    queryKey: ['health'],
    queryFn: async () => (await api.GET('/health')).data,
  });

  const status = health.isPending
    ? { label: 'Vérification…', tone: 'text-slate-500' }
    : health.data?.status === 'ok'
      ? { label: 'Opérationnelle', tone: 'text-emerald-700 dark:text-emerald-400' }
      : { label: 'Indisponible', tone: 'text-red-700 dark:text-red-400' };

  return (
    <section className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">Tableau de bord</h1>
        <p className="mt-2 text-slate-600 dark:text-slate-400">
          Bonjour {me?.user.name}.{' '}
          {me?.company !== null && me?.company !== undefined
            ? `Entreprise active : ${me.company.trade_name}.`
            : 'Aucune entreprise active : choisissez-en une dans le sélecteur.'}
        </p>
      </div>
      {me?.company !== null && me?.company !== undefined && <DashboardPanels />}
      <Card>
        <p className="text-sm text-slate-500 dark:text-slate-400">API</p>
        <p className={`text-lg font-medium ${status.tone}`} role="status">
          {status.label}
        </p>
      </Card>
    </section>
  );
}
