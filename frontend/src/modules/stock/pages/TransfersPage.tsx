import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { useCan } from '@/modules/auth/session';
import { variantLabel, VariantPicker, type VariantRef } from '@/modules/catalog/components/VariantPicker';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouseNames, useWarehouses } from '@/modules/stock/hooks';

function NewTransfer({ onCancel }: { onCancel: () => void }) {
  const navigate = useNavigate();
  const warehouses = useWarehouses();
  const active = (warehouses.data ?? []).filter((w) => w.status === 'active');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [lines, setLines] = useState<{ variant: VariantRef; quantity: string }[]>([]);
  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/stock-transfers', {
          body: {
            from_warehouse_id: from,
            to_warehouse_id: to,
            lines: lines.map((l) => ({
              variant_id: l.variant.id,
              quantity: Number.parseInt(l.quantity || '0', 10),
            })),
          },
        })
      ).data?.data,
    onSuccess: (transfer) => transfer !== undefined && void navigate(`/stock/transferts/${transfer.id}`),
  });

  return (
    <Card title="Nouveau transfert">
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <SelectField label="Dépôt de départ" value={from} onChange={(e) => setFrom(e.target.value)}>
            <option value="">Choisir…</option>
            {active.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </SelectField>
          <SelectField label="Dépôt d’arrivée" value={to} onChange={(e) => setTo(e.target.value)}>
            <option value="">Choisir…</option>
            {active
              .filter((w) => w.id !== from)
              .map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
          </SelectField>
        </div>
        <VariantPicker
          label="Ajouter un article"
          onSelect={(variant) =>
            !lines.some((l) => l.variant.id === variant.id) &&
            setLines([...lines, { variant, quantity: '1' }])
          }
        />
        {lines.map((line, index) => (
          <div key={line.variant.id} className="flex items-center gap-2 text-sm">
            <span className="flex-1">{variantLabel(line.variant)}</span>
            <input
              aria-label="Quantité"
              type="number"
              min={1}
              className="w-24 rounded-md border border-slate-300 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900"
              value={line.quantity}
              onChange={(e) =>
                setLines(lines.map((l, i) => (i === index ? { ...l, quantity: e.target.value } : l)))
              }
            />
            <button
              type="button"
              aria-label="Retirer"
              className="p-1 text-red-700"
              onClick={() => setLines(lines.filter((_, i) => i !== index))}
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        ))}
        <ErrorAlert error={create.error} />
        <div className="flex gap-2">
          <Button
            onClick={() => create.mutate()}
            loading={create.isPending}
            disabled={lines.length === 0 || from === '' || to === ''}
          >
            Enregistrer le brouillon
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            Annuler
          </Button>
        </div>
      </div>
    </Card>
  );
}

export function TransfersPage() {
  const canManage = useCan('transfers.manage');
  const warehouseName = useWarehouseNames();
  const [creating, setCreating] = useState(false);
  const transfers = useQuery({
    queryKey: ['transfers'],
    queryFn: async () => (await api.GET('/stock-transfers', { params: { query: { per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Transferts entre dépôts"
        description="Confirmer réserve la marchandise, expédier la fait sortir, réceptionner la fait entrer (un écart est déclaré en perte)."
        actions={
          canManage && !creating && <Button onClick={() => setCreating(true)}>Nouveau transfert</Button>
        }
      />
      {creating && <NewTransfer onCancel={() => setCreating(false)} />}
      <Card>
        {transfers.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(transfers.data?.data ?? []).map((t) => (
              <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/stock/transferts/${t.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {t.number}
                  </Link>{' '}
                  · {warehouseName(t.from_warehouse_id)} → {warehouseName(t.to_warehouse_id)} ·{' '}
                  {formatDateTime(t.created_at)}
                </span>
                <StatusBadge status={t.status} />
              </li>
            ))}
            {transfers.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucun transfert.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

export function TransferDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canManage = useCan('transfers.manage');
  const warehouseName = useWarehouseNames();
  const keys = useRef({
    confirm: newIdempotencyKey(),
    ship: newIdempotencyKey(),
    receive: newIdempotencyKey(),
  });
  const [received, setReceived] = useState<Record<string, string>>({});
  const transfer = useQuery({
    queryKey: ['transfer', id],
    queryFn: async () => (await api.GET('/stock-transfers/{id}', { params: { path: { id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['transfer', id] });
    void queryClient.invalidateQueries({ queryKey: ['transfers'] });
    void queryClient.invalidateQueries({ queryKey: ['stock'] });
  };
  const action = useMutation({
    mutationFn: async (name: 'confirm' | 'ship' | 'receive' | 'cancel') => {
      const path = { path: { id } };
      if (name === 'confirm')
        return api.POST('/stock-transfers/{id}/confirm', {
          params: { ...path, header: { 'Idempotency-Key': keys.current.confirm } },
        });
      if (name === 'ship')
        return api.POST('/stock-transfers/{id}/ship', {
          params: { ...path, header: { 'Idempotency-Key': keys.current.ship } },
          body: {},
        });
      if (name === 'receive') {
        const body = Object.fromEntries(
          Object.entries(received)
            .filter(([, v]) => v !== '')
            .map(([k, v]) => [k, Number.parseInt(v, 10)]),
        );
        return api.POST('/stock-transfers/{id}/receive', {
          params: { ...path, header: { 'Idempotency-Key': keys.current.receive } },
          body: { received: body },
        });
      }
      return api.POST('/stock-transfers/{id}/cancel', { params: path });
    },
    onSuccess: refresh,
  });

  if (transfer.isPending) return <Spinner />;
  if (transfer.data === undefined) return <ErrorAlert error={transfer.error} />;
  const t = transfer.data;

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/stock/transferts" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Transferts
      </Link>
      <PageHeader
        title={`Transfert ${t.number}`}
        description={`${warehouseName(t.from_warehouse_id)} → ${warehouseName(t.to_warehouse_id)}`}
        actions={<StatusBadge status={t.status} />}
      />
      <ErrorAlert error={action.error} />
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(t.lines ?? []).map((l) => (
            <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                {variantLabel(l.variant)}{' '}
                {l.lot_number !== null && (
                  <span className="text-xs text-slate-500">· lot {l.lot_number}</span>
                )}
              </span>
              <span className="flex items-center gap-2">
                demandé {l.qty_requested} · envoyé {l.qty_sent}
                {t.status === 'in_transit' && canManage ? (
                  <input
                    aria-label="Quantité reçue"
                    type="number"
                    min={0}
                    max={l.qty_sent}
                    placeholder={String(l.qty_sent)}
                    className="w-20 rounded-md border border-slate-300 bg-white px-2 py-1 dark:border-slate-700 dark:bg-slate-900"
                    value={received[l.id] ?? ''}
                    onChange={(e) => setReceived({ ...received, [l.id]: e.target.value })}
                  />
                ) : (
                  t.status === 'received' && (
                    <>
                      · reçu {l.qty_received}
                      {l.qty_discrepancy > 0 && (
                        <strong className="text-red-700"> (perte {l.qty_discrepancy})</strong>
                      )}
                    </>
                  )
                )}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      {canManage && (
        <div className="flex flex-wrap gap-2">
          {t.status === 'draft' && (
            <Button onClick={() => action.mutate('confirm')}>Confirmer (réserver)</Button>
          )}
          {t.status === 'confirmed' && <Button onClick={() => action.mutate('ship')}>Expédier</Button>}
          {t.status === 'in_transit' && (
            <Button onClick={() => action.mutate('receive')}>Réceptionner</Button>
          )}
          {(t.status === 'draft' || t.status === 'confirmed') && (
            <Button variant="ghost" onClick={() => action.mutate('cancel')}>
              Annuler
            </Button>
          )}
        </div>
      )}
      {t.shipped_at !== null && (
        <p className="text-xs text-slate-500">
          Expédié le {formatDateTime(t.shipped_at)}
          {t.received_at !== null && ` · réceptionné le ${formatDateTime(t.received_at)}`}
        </p>
      )}
    </div>
  );
}
