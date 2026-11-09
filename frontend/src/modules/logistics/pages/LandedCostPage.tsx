import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { Link, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

/** Détail d'un calcul de coût de revient : formule standard, répartition au CBM, anomalies, finalisation. */
export function LandedCostPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canManage = useCan('costing.manage');
  const finalizeKey = useRef(newIdempotencyKey());
  const calc = useQuery({
    queryKey: ['landed-cost', id],
    queryFn: async () =>
      (await api.GET('/landed-costs/{calculation}', { params: { path: { calculation: id } } })).data?.data,
  });
  const finalize = useMutation({
    mutationFn: async () =>
      api.POST('/landed-costs/{calculation}/finalize', {
        params: { path: { calculation: id }, header: { 'Idempotency-Key': finalizeKey.current } },
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['landed-cost', id] });
      void queryClient.invalidateQueries({ queryKey: ['shipment'] });
    },
  });

  if (calc.isPending) return <Spinner />;
  if (calc.data === undefined) return <ErrorAlert error={calc.error} />;
  const c = calc.data;
  const money = (amount: string) => formatMoney({ amount, currency: c.reference_currency });

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <Link
        to={`/arrivages/${c.shipment_id}`}
        className="text-sm text-brand-700 underline dark:text-brand-500"
      >
        ← Arrivage
      </Link>
      <PageHeader
        title={`Coût de revient — révision ${c.revision}`}
        description={`Calculé le ${formatDateTime(c.computed_at)} · ${c.total_cbm} m³ · frais communs ${money(c.total_common_fees)} · frais directs ${money(c.total_direct_fees)}`}
        actions={<StatusBadge status={c.status} />}
      />
      {c.blocking_issues.length > 0 && (
        <Alert tone="warning">
          <strong>Finalisation bloquée</strong>
          <ul className="list-disc pl-5">
            {c.blocking_issues.map((i) => (
              <li key={i.code + i.message}>{i.message}</li>
            ))}
          </ul>
        </Alert>
      )}
      <Card title="Lignes">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="text-left text-xs text-slate-500">
              <tr>
                <th className="py-1">Article</th>
                <th className="text-right">Qté</th>
                <th className="text-right">CBM</th>
                <th className="text-right">Achat converti</th>
                <th className="text-right">Frais communs</th>
                <th className="text-right">Frais directs</th>
                <th className="text-right">Coût total</th>
                <th className="text-right">Pièce</th>
                <th className="text-right">Carton</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
              {(c.lines ?? []).map((l) => (
                <tr key={l.id}>
                  <td className="py-2">{variantLabel(l.variant)}</td>
                  <td className="text-right">{l.qty_accepted}</td>
                  <td className="text-right">{l.line_cbm}</td>
                  <td
                    className="text-right"
                    title={`${l.purchase_amount_foreign} ${l.purchase_currency} × ${l.exchange_rate}`}
                  >
                    {money(l.purchase_amount_converted)}
                  </td>
                  <td className="text-right">{money(l.allocated_common_fees)}</td>
                  <td className="text-right">{money(l.direct_fees)}</td>
                  <td className="text-right font-medium">{money(l.total_cost)}</td>
                  <td className="text-right font-semibold">{money(l.unit_cost_piece)}</td>
                  <td className="text-right">
                    {l.unit_cost_carton !== null ? money(l.unit_cost_carton) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-3 text-right font-semibold">Total {money(c.total_cost)}</p>
      </Card>
      {c.discrepancies.length > 0 && (
        <Card title="Écarts de réception (non imputés au stock)">
          <ul className="text-sm">
            {c.discrepancies.map((d, i) => (
              <li key={i}>{JSON.stringify(d)}</li>
            ))}
          </ul>
        </Card>
      )}
      {(c.valuation_adjustments ?? []).length > 0 && (
        <Card title="Ajustements de valorisation des sorties déjà valorisées">
          <ul className="text-sm">
            {(c.valuation_adjustments ?? []).map((a) => (
              <li key={a.id}>
                {a.quantity} pièce(s) : {money(a.old_unit_cost)} → {money(a.new_unit_cost)} · écart{' '}
                {money(a.delta_amount)}
              </li>
            ))}
          </ul>
        </Card>
      )}
      <ErrorAlert error={finalize.error} />
      {canManage && c.status === 'computed' && (
        <Button
          onClick={() => finalize.mutate()}
          disabled={c.blocking_issues.length > 0}
          loading={finalize.isPending}
        >
          Finaliser : coût définitif des lots
        </Button>
      )}
    </div>
  );
}
