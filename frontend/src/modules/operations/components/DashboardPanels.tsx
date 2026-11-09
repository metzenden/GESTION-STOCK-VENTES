import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { api } from '@/api/client';
import { Card } from '@/components/ui/Card';
import { formatMoney } from '@/lib/money';
import { useCan, useMe } from '@/modules/auth/session';

/** Tableau de bord (L6-04) : activité du jour, alertes, et indicateurs de gestion pour les profils financiers. */
export function DashboardPanels() {
  const { data: me } = useMe();
  const currency = me?.company?.reference_currency ?? 'XOF';
  const financial = useCan('reports.financial');
  const dashboard = useQuery({
    queryKey: ['dashboard'],
    enabled: me?.company !== null,
    queryFn: async () => (await api.GET('/dashboard')).data?.data,
  });
  const kpi = useQuery({
    queryKey: ['indicators'],
    enabled: financial,
    queryFn: async () => (await api.GET('/reports/indicators')).data?.data,
  });
  const d = dashboard.data as Record<string, unknown> | undefined;
  const k = kpi.data as Record<string, string> | undefined;
  const alerts = (d?.alerts ?? []) as { code: string; level: string; message: string; link: string }[];
  const m = (v: unknown) => formatMoney({ amount: String(v ?? '0'), currency });

  if (d === undefined) return null;

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <p className="text-sm text-slate-500">Ventes facturées aujourd'hui</p>
          <p className="text-xl font-semibold">{m(d.sales_today)}</p>
        </Card>
        <Card>
          <p className="text-sm text-slate-500">Encaissé aujourd'hui</p>
          <p className="text-xl font-semibold">{m(d.collected_today)}</p>
        </Card>
        <Card>
          <p className="text-sm text-slate-500">Livraisons à préparer</p>
          <p className="text-xl font-semibold">{String(d.deliveries_to_prepare)}</p>
        </Card>
        <Card>
          <p className="text-sm text-slate-500">Approbations en attente</p>
          <p className="text-xl font-semibold">{String(d.pending_approvals)}</p>
        </Card>
      </div>
      {alerts.length > 0 && (
        <Card title="Alertes">
          <ul className="space-y-1 text-sm">
            {alerts.map((a) => (
              <li key={a.code}>
                <Link
                  to={a.link}
                  className={a.level === 'danger' ? 'text-red-700 underline' : 'text-amber-700 underline'}
                >
                  {a.message}
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {k !== undefined && (
        <Card title="Gestion du mois (CA net, marge, résultat — distincts de la trésorerie)">
          <dl className="grid gap-3 text-sm sm:grid-cols-3">
            {[
              ['CA net', k.net_revenue],
              ['Coût des ventes', k.cost_of_goods_sold],
              ['Marge brute', k.gross_margin],
              ['Charges', k.charges],
              ['Résultat net de gestion', k.net_result],
              ['Créances', k.receivables],
            ].map(([label, value]) => (
              <div key={label}>
                <dt className="text-slate-500">{label}</dt>
                <dd className="text-lg font-semibold">{m(value)}</dd>
              </div>
            ))}
          </dl>
        </Card>
      )}
    </div>
  );
}
