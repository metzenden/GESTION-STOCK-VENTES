import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel, VariantPicker, type VariantRef } from '@/modules/catalog/components/VariantPicker';
import { decimalInput, unitPrice, useSuppliers } from '@/modules/purchasing/hooks';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouses } from '@/modules/stock/hooks';

const input =
  'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';

function NewOrder({ onCancel }: { onCancel: () => void }) {
  const navigate = useNavigate();
  const suppliers = useSuppliers('goods');
  const [supplierId, setSupplierId] = useState('');
  const [rate, setRate] = useState('');
  const [reference, setReference] = useState('');
  const [lines, setLines] = useState<{ variant: VariantRef; qty: string; price: string }[]>([]);
  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/purchase-orders', {
          body: {
            supplier_id: supplierId,
            supplier_reference: reference === '' ? null : reference,
            estimated_rate: rate === '' ? null : decimalInput(rate),
            lines: lines.map((l) => ({
              variant_id: l.variant.id,
              qty_units: Number.parseInt(l.qty || '0', 10),
              unit_price: decimalInput(l.price || '0'),
            })),
          },
        })
      ).data?.data,
    onSuccess: (order) => order !== undefined && void navigate(`/achats/commandes/${order.id}`),
  });
  const currency = suppliers.data?.find((s) => s.id === supplierId)?.currency;

  return (
    <Card title="Nouvelle commande d’achat">
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <SelectField label="Fournisseur" value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
            <option value="">Choisir…</option>
            {(suppliers.data ?? [])
              .filter((s) => s.status === 'active')
              .map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.currency})
                </option>
              ))}
          </SelectField>
          <TextField
            label="Référence fournisseur"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
          />
          <TextField
            label={`Taux estimé ${currency ?? ''} → devise de référence`}
            hint="Sert au coût provisoire si aucun taux n’est enregistré."
            inputMode="decimal"
            value={rate}
            onChange={(e) => setRate(e.target.value)}
          />
        </div>
        <VariantPicker
          label="Ajouter un article"
          onSelect={(variant) =>
            !lines.some((l) => l.variant.id === variant.id) &&
            setLines([...lines, { variant, qty: '1', price: '' }])
          }
        />
        {lines.map((line, index) => (
          <div key={line.variant.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="min-w-48 flex-1">{variantLabel(line.variant)}</span>
            <input
              aria-label="Quantité (pièces)"
              type="number"
              min={1}
              className={`${input} w-24`}
              value={line.qty}
              onChange={(e) =>
                setLines(lines.map((l, i) => (i === index ? { ...l, qty: e.target.value } : l)))
              }
            />
            <input
              aria-label={`Prix unitaire ${currency ?? ''}`}
              placeholder={`Prix ${currency ?? ''}`}
              inputMode="decimal"
              className={`${input} w-32`}
              value={line.price}
              onChange={(e) =>
                setLines(lines.map((l, i) => (i === index ? { ...l, price: e.target.value } : l)))
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
            disabled={supplierId === '' || lines.length === 0}
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

export function PurchaseOrdersPage() {
  const canManage = useCan('purchase_orders.manage');
  const [creating, setCreating] = useState(false);
  const orders = useQuery({
    queryKey: ['purchase-orders'],
    queryFn: async () => (await api.GET('/purchase-orders', { params: { query: { per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Commandes d’achat"
        description="La confirmation crée l’engagement envers le fournisseur ; les réceptions font avancer la commande."
        actions={
          canManage && !creating && <Button onClick={() => setCreating(true)}>Nouvelle commande</Button>
        }
      />
      {creating && <NewOrder onCancel={() => setCreating(false)} />}
      <Card>
        {orders.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(orders.data?.data ?? []).map((o) => (
              <li key={o.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/achats/commandes/${o.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {o.number}
                  </Link>{' '}
                  · {o.supplier?.name} · {o.order_date}
                </span>
                <span className="flex items-center gap-3">
                  {formatMoney({ amount: o.total_amount, currency: o.currency })}
                  <StatusBadge status={o.status} />
                </span>
              </li>
            ))}
            {orders.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucune commande.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

export function PurchaseOrderDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const canManage = useCan('purchase_orders.manage');
  const canReceive = useCan('shipments.manage');
  const warehouses = useWarehouses();
  const [warehouseId, setWarehouseId] = useState('');
  const confirmKey = useRef(newIdempotencyKey());
  const order = useQuery({
    queryKey: ['purchase-order', id],
    queryFn: async () => (await api.GET('/purchase-orders/{id}', { params: { path: { id } } })).data?.data,
  });
  const action = useMutation({
    mutationFn: async (name: 'confirm' | 'close' | 'cancel') => {
      const path = { path: { id } };
      if (name === 'confirm')
        return api.POST('/purchase-orders/{id}/confirm', {
          params: { ...path, header: { 'Idempotency-Key': confirmKey.current } },
        });
      if (name === 'close')
        return api.POST('/purchase-orders/{id}/close', { params: path, body: { release_remaining: true } });
      return api.POST('/purchase-orders/{id}/cancel', { params: path });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['purchase-order', id] });
      void queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
    },
  });
  const receive = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/goods-receipts', {
          body: { purchase_order_id: id, warehouse_id: warehouseId, counting_mode: 'manual' },
        })
      ).data?.data,
    onSuccess: (receipt) => receipt !== undefined && void navigate(`/receptions/${receipt.id}`),
  });

  if (order.isPending) return <Spinner />;
  if (order.data === undefined) return <ErrorAlert error={order.error} />;
  const o = order.data;
  const receivable = o.status === 'confirmed' || o.status === 'partially_received';

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/achats/commandes" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Commandes d’achat
      </Link>
      <PageHeader
        title={`Commande ${o.number}`}
        description={`${o.supplier?.name ?? ''} · ${o.order_date}${o.estimated_rate !== null ? ` · taux estimé ${o.estimated_rate}` : ''}`}
        actions={<StatusBadge status={o.status} />}
      />
      <ErrorAlert error={action.error ?? receive.error} />
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(o.lines ?? []).map((l) => (
            <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                {variantLabel(l.variant)}{' '}
                <span className="text-slate-500">
                  · {l.qty_units} {l.unit?.label ?? ''}
                  {l.unit_factor > 1 && ` (${l.qty_base} pièces)`}
                </span>
              </span>
              <span>
                {formatMoney(unitPrice(l.unit_price, o.currency), { exact: true })} ·{' '}
                <strong>{formatMoney({ amount: l.line_total, currency: o.currency })}</strong> · reçu{' '}
                {l.qty_received}/{l.qty_base}
              </span>
            </li>
          ))}
        </ul>
        <p className="mt-3 text-right text-base font-semibold">
          Total {formatMoney({ amount: o.total_amount, currency: o.currency })}
        </p>
      </Card>
      {canManage && (
        <div className="flex flex-wrap gap-2">
          {o.status === 'draft' && (
            <Button onClick={() => action.mutate('confirm')}>Confirmer la commande</Button>
          )}
          {(o.status === 'partially_received' || o.status === 'received') && (
            <Button variant="secondary" onClick={() => action.mutate('close')}>
              Clôturer (abandonner le reliquat)
            </Button>
          )}
          {(o.status === 'draft' || o.status === 'confirmed') && (
            <Button variant="ghost" onClick={() => action.mutate('cancel')}>
              Annuler la commande
            </Button>
          )}
        </div>
      )}
      {canReceive && receivable && (
        <Card title="Réception directe (sans arrivage)">
          <div className="flex flex-wrap items-end gap-2">
            <SelectField label="Dépôt" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
              <option value="">Choisir…</option>
              {(warehouses.data ?? [])
                .filter((w) => w.status === 'active')
                .map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
            </SelectField>
            <Button
              onClick={() => receive.mutate()}
              disabled={warehouseId === ''}
              loading={receive.isPending}
            >
              Ouvrir une réception
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
