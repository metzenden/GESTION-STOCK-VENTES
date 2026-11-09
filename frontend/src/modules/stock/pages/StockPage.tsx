import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { movementLabels, useWarehouseNames, useWarehouses } from '@/modules/stock/hooks';

function LotsPanel({ variantId, warehouseId }: { variantId: string; warehouseId: string }) {
  const lots = useQuery({
    queryKey: ['stock', 'lots', variantId, warehouseId],
    queryFn: async () =>
      (
        await api.GET('/stock/lots', {
          params: {
            query: { 'filter[variant_id]': variantId, 'filter[warehouse_id]': warehouseId, per_page: 50 },
          },
        })
      ).data,
  });

  if (lots.isPending) return <Spinner />;

  return (
    <ul className="mt-2 space-y-1 rounded bg-slate-50 p-2 text-xs dark:bg-slate-800/50">
      {(lots.data?.data ?? []).map((l) => (
        <li key={l.id} className="flex flex-wrap justify-between gap-2">
          <span className="font-mono">{l.lot_number}</span>
          <span>
            reçu le {formatDateTime(l.received_at)} · libre {l.free} / {l.on_hand}
            {l.unit_cost !== undefined && ` · coût ${l.unit_cost.amount} ${l.unit_cost.currency}`}
            {l.cost_status === 'provisional' && ' (provisoire)'}
          </span>
        </li>
      ))}
    </ul>
  );
}

function Positions() {
  const warehouses = useWarehouses();
  const warehouseName = useWarehouseNames();
  const [warehouse, setWarehouse] = useState('');
  const [low, setLow] = useState(false);
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const positions = useQuery({
    queryKey: ['stock', 'positions', warehouse, low, search, page],
    queryFn: async () =>
      (
        await api.GET('/stock/positions', {
          params: {
            query: {
              page,
              per_page: 50,
              ...(warehouse !== '' ? { 'filter[warehouse_id]': warehouse } : {}),
              ...(low ? { 'filter[low]': true } : {}),
              ...(search !== '' ? { q: search } : {}),
            },
          },
        })
      ).data,
    placeholderData: (previous) => previous,
  });

  return (
    <Card>
      <div className="mb-4 grid gap-3 sm:grid-cols-3 sm:items-end">
        <TextField
          label="Rechercher"
          placeholder="Nom, référence, SKU"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <SelectField
          label="Dépôt"
          value={warehouse}
          onChange={(e) => {
            setWarehouse(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Tous les dépôts</option>
          {(warehouses.data ?? []).map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </SelectField>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={low}
            onChange={(e) => {
              setLow(e.target.checked);
              setPage(1);
            }}
          />{' '}
          Stock faible uniquement
        </label>
      </div>
      <p className="mb-2 text-xs text-slate-500">
        Disponible = physique − réservé − bloqué − en livraison. « En transit » arrive d’un autre dépôt et
        n’est pas encore vendable.
      </p>
      {positions.isPending ? (
        <Spinner />
      ) : positions.isError ? (
        <ErrorAlert error={positions.error} />
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[44rem] text-sm">
              <thead>
                <tr className="text-left text-slate-500">
                  <th className="py-2 font-medium">Article</th>
                  <th className="py-2 font-medium">Dépôt</th>
                  <th className="py-2 text-right font-medium">Physique</th>
                  <th className="py-2 text-right font-medium">Réservé</th>
                  <th className="py-2 text-right font-medium">Bloqué</th>
                  <th className="py-2 text-right font-medium">En livraison</th>
                  <th className="py-2 text-right font-medium">Disponible</th>
                  <th className="py-2 text-right font-medium">En transit</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {(positions.data?.data ?? []).map((p) => {
                  const key = `${p.warehouse_id}|${p.variant?.id ?? ''}`;
                  return (
                    <tr key={key} className="align-top">
                      <td className="py-2">
                        <button
                          type="button"
                          className="text-left text-brand-700 hover:underline dark:text-brand-500"
                          onClick={() => setOpen(open === key ? null : key)}
                        >
                          {variantLabel(p.variant)}
                        </button>
                        {open === key && p.variant !== null && (
                          <LotsPanel variantId={p.variant.id} warehouseId={p.warehouse_id} />
                        )}
                      </td>
                      <td className="py-2">{warehouseName(p.warehouse_id)}</td>
                      <td className="py-2 text-right tabular-nums">{p.on_hand}</td>
                      <td className="py-2 text-right tabular-nums">{p.reserved}</td>
                      <td className="py-2 text-right tabular-nums">{p.blocked}</td>
                      <td className="py-2 text-right tabular-nums">{p.in_delivery}</td>
                      <td className="py-2 text-right font-semibold tabular-nums">
                        {p.available} {p.is_low && <Badge tone="danger">faible</Badge>}
                      </td>
                      <td className="py-2 text-right tabular-nums">{p.in_transit}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="mt-3 flex items-center justify-between text-sm">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Précédent
            </Button>
            <span>
              Page {positions.data?.meta.page} / {positions.data?.meta.last_page}
            </span>
            <Button
              variant="secondary"
              disabled={page >= (positions.data?.meta.last_page ?? 1)}
              onClick={() => setPage(page + 1)}
            >
              Suivant
            </Button>
          </div>
        </>
      )}
    </Card>
  );
}

function Movements() {
  const warehouseName = useWarehouseNames();
  const [page, setPage] = useState(1);
  const movements = useQuery({
    queryKey: ['stock', 'movements', page],
    queryFn: async () =>
      (await api.GET('/stock/movements', { params: { query: { page, per_page: 50 } } })).data,
  });

  return (
    <Card title="Derniers mouvements">
      {movements.isPending ? (
        <Spinner />
      ) : (
        <>
          <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
            {(movements.data?.data ?? []).map((m) => (
              <li key={m.id} className="flex flex-wrap justify-between gap-2 py-2">
                <span>
                  <strong
                    className={
                      m.quantity < 0
                        ? 'text-red-700 dark:text-red-400'
                        : 'text-emerald-700 dark:text-emerald-400'
                    }
                  >
                    {m.quantity > 0 ? '+' : ''}
                    {m.quantity}
                  </strong>{' '}
                  · {movementLabels[m.movement_type] ?? m.movement_type} · lot {m.lot_number} ·{' '}
                  {warehouseName(m.warehouse_id)}
                  {m.total_cost !== undefined && ` · ${formatMoney(m.total_cost)}`}
                </span>
                <span className="text-xs text-slate-500">{formatDateTime(m.occurred_at)}</span>
              </li>
            ))}
          </ul>
          <div className="mt-3 flex items-center justify-between text-sm">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Précédent
            </Button>
            <Button
              variant="secondary"
              disabled={page >= (movements.data?.meta.last_page ?? 1)}
              onClick={() => setPage(page + 1)}
            >
              Suivant
            </Button>
          </div>
        </>
      )}
    </Card>
  );
}

function Valuation() {
  const valuation = useQuery({
    queryKey: ['stock', 'valuation'],
    queryFn: async () => (await api.GET('/stock/valuation')).data?.data,
  });

  if (valuation.data === undefined) return null;

  return (
    <Card>
      <p className="text-sm text-slate-500">Valeur du stock (au coût des lots)</p>
      <p className="text-2xl font-semibold">{formatMoney(valuation.data.total)}</p>
      {valuation.data.provisional_lots > 0 && (
        <p className="text-xs text-amber-700">
          {valuation.data.provisional_lots} lot(s) encore au coût provisoire.
        </p>
      )}
    </Card>
  );
}

export function StockPage() {
  const canSeeCosts = useCan('products.view_sensitive');
  const [tab, setTab] = useState<'positions' | 'movements'>('positions');

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <PageHeader
        title="Stocks"
        description="Compartiments par dépôt et article, lots et mouvements."
        actions={
          <div className="flex gap-1">
            <Button
              variant={tab === 'positions' ? 'primary' : 'secondary'}
              onClick={() => setTab('positions')}
            >
              État des stocks
            </Button>
            <Button
              variant={tab === 'movements' ? 'primary' : 'secondary'}
              onClick={() => setTab('movements')}
            >
              Mouvements
            </Button>
          </div>
        }
      />
      {canSeeCosts && <Valuation />}
      {tab === 'positions' ? <Positions /> : <Movements />}
    </div>
  );
}
