import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel, VariantPicker, type VariantRef } from '@/modules/catalog/components/VariantPicker';
import { useCustomers } from '@/modules/sales/hooks';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

type Delivery = components['schemas']['Delivery'];

const input =
  'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';

/** Saisie d'un devis ou d'une commande : client, articles, quantités, prix proposé modifiable, remise. */
export function NewSalesDocument({ kind, onCancel }: { kind: 'order' | 'quotation'; onCancel: () => void }) {
  const navigate = useNavigate();
  const [term, setTerm] = useState('');
  const customers = useCustomers(term);
  const [customerId, setCustomerId] = useState('');
  const [mode, setMode] = useState<'pickup' | 'delivery'>('pickup');
  const [address, setAddress] = useState('');
  const [lines, setLines] = useState<{ variant: VariantRef; qty: string; price: string; discount: string }[]>(
    [],
  );
  const create = useMutation({
    mutationFn: async () => {
      const body = {
        customer_id: customerId,
        lines: lines.map((l) => ({
          variant_id: l.variant.id,
          qty_units: Number.parseInt(l.qty || '0', 10),
          ...(l.price !== '' ? { unit_price: l.price.replace(',', '.') } : {}),
          ...(l.discount !== ''
            ? { discount_type: 'percent' as const, discount_value: l.discount.replace(',', '.') }
            : {}),
        })),
      };
      if (kind === 'quotation')
        return { id: (await api.POST('/quotations', { body })).data?.data.id, path: '/devis/' };
      return {
        id: (
          await api.POST('/sales-orders', {
            body: { ...body, fulfilment_mode: mode, delivery_address: address || null },
          })
        ).data?.data.id,
        path: '/commandes/',
      };
    },
    onSuccess: (r) => r.id !== undefined && void navigate(`${r.path}${r.id}`),
  });

  return (
    <Card title={kind === 'order' ? 'Nouvelle commande' : 'Nouveau devis'}>
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label="Rechercher un client" value={term} onChange={(e) => setTerm(e.target.value)} />
          <SelectField label="Client" value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
            <option value="">Choisir…</option>
            {(customers.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} {c.phone !== null ? `(${c.phone})` : ''}
              </option>
            ))}
          </SelectField>
          {kind === 'order' && (
            <>
              <SelectField
                label="Remise de la marchandise"
                value={mode}
                onChange={(e) => setMode(e.target.value as 'pickup' | 'delivery')}
              >
                <option value="pickup">Retrait sur place</option>
                <option value="delivery">Livraison</option>
              </SelectField>
              {mode === 'delivery' && (
                <TextField
                  label="Adresse de livraison"
                  value={address}
                  onChange={(e) => setAddress(e.target.value)}
                />
              )}
            </>
          )}
        </div>
        <VariantPicker
          label="Ajouter un article"
          onSelect={(v) =>
            !lines.some((l) => l.variant.id === v.id) &&
            setLines([...lines, { variant: v, qty: '1', price: '', discount: '' }])
          }
        />
        {lines.map((l, i) => (
          <div key={l.variant.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="min-w-40 flex-1">{variantLabel(l.variant)}</span>
            <input
              aria-label="Quantité"
              type="number"
              min={1}
              className={`${input} w-20`}
              value={l.qty}
              onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, qty: e.target.value } : x)))}
            />
            <input
              aria-label="Prix (vide = grille)"
              placeholder="Prix grille"
              inputMode="decimal"
              className={`${input} w-28`}
              value={l.price}
              onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, price: e.target.value } : x)))}
            />
            <input
              aria-label="Remise %"
              placeholder="Remise %"
              inputMode="decimal"
              className={`${input} w-24`}
              value={l.discount}
              onChange={(e) =>
                setLines(lines.map((x, j) => (j === i ? { ...x, discount: e.target.value } : x)))
              }
            />
            <button
              type="button"
              aria-label="Retirer"
              className="p-1 text-red-700"
              onClick={() => setLines(lines.filter((_, j) => j !== i))}
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
            disabled={customerId === '' || lines.length === 0}
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

export function SalesOrdersPage() {
  const canCreate = useCan('sales.create');
  const [creating, setCreating] = useState(false);
  const orders = useQuery({
    queryKey: ['sales-orders'],
    queryFn: async () => (await api.GET('/sales-orders', { params: { query: { per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Commandes clients"
        description="La confirmation réserve tout le stock et émet la facture ; la remise fait sortir la marchandise."
        actions={
          canCreate && !creating && <Button onClick={() => setCreating(true)}>Nouvelle commande</Button>
        }
      />
      {creating && <NewSalesDocument kind="order" onCancel={() => setCreating(false)} />}
      <Card>
        {orders.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(orders.data?.data ?? []).map((o) => (
              <li key={o.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/commandes/${o.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {o.number}
                  </Link>{' '}
                  · {o.customer?.name} {o.channel === 'counter' && '· comptoir'}{' '}
                  {o.has_dispute && <strong className="text-red-700">· litige</strong>}
                </span>
                <span className="flex items-center gap-3">
                  {formatMoney({ amount: o.total, currency: o.currency })}
                  <StatusBadge status={o.status} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

function DeliveryCard({ delivery, onChange }: { delivery: Delivery; onChange: () => void }) {
  const canShip = useCan('deliveries.ship');
  const canHand = useCan('deliveries.handover');
  const canPrepare = useCan('deliveries.prepare');
  const [reason, setReason] = useState('');
  const act = useMutation({
    mutationFn: async (name: 'ready' | 'ship' | 'deliver' | 'fail' | 'return' | 'cancel') => {
      const path = { path: { delivery: delivery.id } };
      const header = { 'Idempotency-Key': newIdempotencyKey() };
      switch (name) {
        case 'ready':
          return api.POST('/deliveries/{delivery}/ready', { params: path });
        case 'ship':
          return api.POST('/deliveries/{delivery}/ship', { params: { ...path, header }, body: {} });
        case 'deliver':
          return api.POST('/deliveries/{delivery}/deliver', { params: { ...path, header }, body: {} });
        case 'fail':
          return api.POST('/deliveries/{delivery}/fail', { params: { ...path, header }, body: { reason } });
        case 'return':
          return api.POST('/deliveries/{delivery}/return-to-warehouse', { params: { ...path, header } });
        default:
          return api.POST('/deliveries/{delivery}/cancel', { params: path });
      }
    },
    onSuccess: onChange,
  });
  const s = delivery.status;

  return (
    <li className="space-y-2 py-2 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>
          <strong>{delivery.number}</strong> · {delivery.mode === 'pickup' ? 'retrait' : 'livraison'} ·{' '}
          {formatDateTime(delivery.created_at)}
          {delivery.attempt > 1 && ` · tentative ${delivery.attempt}`}
        </span>
        <StatusBadge status={s} />
      </div>
      <div className="flex flex-wrap gap-2">
        {s === 'preparing' && canPrepare && (
          <Button variant="secondary" onClick={() => act.mutate('ready')}>
            Préparation terminée
          </Button>
        )}
        {s === 'ready' && delivery.mode === 'delivery' && canShip && (
          <Button variant="secondary" onClick={() => act.mutate('ship')}>
            Expédier
          </Button>
        )}
        {((s === 'ready' && delivery.mode === 'pickup') || s === 'in_delivery') && canHand && (
          <Button onClick={() => act.mutate('deliver')}>Remettre au client</Button>
        )}
        {s === 'in_delivery' && canShip && (
          <>
            <input
              aria-label="Motif d'échec"
              placeholder="Motif d'échec"
              className={input}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <Button variant="ghost" disabled={reason === ''} onClick={() => act.mutate('fail')}>
              Signaler un échec
            </Button>
          </>
        )}
        {s === 'failed' && canShip && (
          <Button variant="secondary" onClick={() => act.mutate('return')}>
            Retour au dépôt
          </Button>
        )}
        {(s === 'preparing' || s === 'ready') && canPrepare && (
          <Button variant="ghost" onClick={() => act.mutate('cancel')}>
            Annuler
          </Button>
        )}
      </div>
      {delivery.failure_reason !== null && (
        <p className="text-xs text-red-700">Échec : {delivery.failure_reason}</p>
      )}
      <ErrorAlert error={act.error} />
    </li>
  );
}

export function SalesOrderDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canConfirm = useCan('sales.confirm');
  const canPrepare = useCan('deliveries.prepare');
  const canCreate = useCan('sales.create');
  const submitKey = useRef(newIdempotencyKey());
  const [warnings, setWarnings] = useState<string[]>([]);
  const [cancelReason, setCancelReason] = useState('');
  const [dispute, setDispute] = useState('');
  const order = useQuery({
    queryKey: ['sales-order', id],
    queryFn: async () =>
      (await api.GET('/sales-orders/{order}', { params: { path: { order: id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['sales-order', id] });
    void queryClient.invalidateQueries({ queryKey: ['sales-orders'] });
  };
  const submit = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/sales-orders/{order}/submit', {
          params: { path: { order: id }, header: { 'Idempotency-Key': submitKey.current } },
        })
      ).data,
    onSuccess: (r) => {
      setWarnings(((r?.meta?.warnings ?? []) as { message?: string }[]).map((w) => w.message ?? ''));
      refresh();
    },
  });
  const action = useMutation({
    mutationFn: async (name: 'cancel' | 'cancel-remaining' | 'deliver' | 'dispute') => {
      const path = { path: { order: id } };
      const header = { 'Idempotency-Key': newIdempotencyKey() };
      if (name === 'deliver') return api.POST('/sales-orders/{order}/deliveries', { params: path, body: {} });
      if (name === 'dispute')
        return api.POST('/sales-orders/{order}/disputes', { params: path, body: { reason: dispute } });
      if (name === 'cancel')
        return api.POST('/sales-orders/{order}/cancel', {
          params: { ...path, header },
          body: { reason: cancelReason },
        });
      return api.POST('/sales-orders/{order}/cancel-remaining', {
        params: { ...path, header },
        body: { reason: cancelReason },
      });
    },
    onSuccess: refresh,
  });

  if (order.isPending) return <Spinner />;
  if (order.data === undefined) return <ErrorAlert error={order.error} />;
  const o = order.data;
  const open = ['confirmed', 'preparing', 'ready', 'in_delivery', 'partially_delivered'].includes(o.status);

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/commandes" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Commandes
      </Link>
      <PageHeader
        title={`Commande ${o.number}`}
        description={`${o.customer?.name ?? ''} · ${o.fulfilment_mode === 'pickup' ? 'retrait sur place' : 'livraison'}`}
        actions={<StatusBadge status={o.status} />}
      />
      {warnings.filter(Boolean).map((w) => (
        <Alert key={w} tone="warning">
          {w}
        </Alert>
      ))}
      <ErrorAlert error={submit.error ?? action.error} />
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(o.lines ?? []).map((l) => (
            <li key={l.id} className="flex flex-wrap justify-between gap-2 py-2">
              <span>
                {l.description}{' '}
                <span className="text-slate-500">
                  · {l.qty_units} {l.unit_label}
                </span>
                {l.price_overridden && <span className="text-xs text-amber-700"> · prix modifié</span>}
              </span>
              <span>
                {formatMoney({ amount: l.line_total, currency: o.currency })}
                {o.status !== 'draft' && (
                  <span className="text-xs text-slate-500">
                    {' '}
                    · livré {l.qty_delivered ?? 0}/{l.qty_base}
                  </span>
                )}
              </span>
            </li>
          ))}
        </ul>
        <p className="mt-3 text-right font-semibold">
          Total {formatMoney({ amount: o.total, currency: o.currency })}
          {o.discount_total !== '0.0000' && (
            <span className="text-xs font-normal text-slate-500">
              {' '}
              (remises {formatMoney({ amount: o.discount_total, currency: o.currency })})
            </span>
          )}
        </p>
      </Card>
      {o.invoice !== null && o.invoice !== undefined && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <Link to={`/factures/${o.invoice.id}`} className="text-brand-700 underline dark:text-brand-500">
              Facture {o.invoice.number}
            </Link>
            <span>
              reste à payer {formatMoney({ amount: o.invoice.balance_due ?? '0', currency: o.currency })}{' '}
              <StatusBadge status={o.invoice.payment_status ?? ''} />
            </span>
          </div>
        </Card>
      )}
      <div className="flex flex-wrap gap-2">
        {o.status === 'draft' && canConfirm && (
          <Button onClick={() => submit.mutate()} loading={submit.isPending}>
            Confirmer la commande
          </Button>
        )}
        {open && canPrepare && (
          <Button variant="secondary" onClick={() => action.mutate('deliver')}>
            Préparer une livraison
          </Button>
        )}
      </div>
      {(o.deliveries ?? []).length > 0 && (
        <Card title="Livraisons">
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(o.deliveries ?? []).map((d) => (
              <DeliveryCard key={d.id} delivery={d} onChange={refresh} />
            ))}
          </ul>
        </Card>
      )}
      {canConfirm && (open || o.status === 'draft') && (
        <Card title="Annulation">
          <div className="flex flex-wrap items-end gap-2">
            <TextField label="Motif" value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
            {o.status === 'partially_delivered' ? (
              <Button
                variant="danger"
                disabled={cancelReason === ''}
                onClick={() => action.mutate('cancel-remaining')}
              >
                Annuler le reliquat
              </Button>
            ) : (
              <Button variant="danger" disabled={cancelReason === ''} onClick={() => action.mutate('cancel')}>
                Annuler la commande
              </Button>
            )}
          </div>
        </Card>
      )}
      {canCreate && o.status !== 'draft' && (
        <Card title={`Litiges${o.has_dispute ? ' (ouvert)' : ''}`}>
          <ul className="text-sm">
            {(o.disputes ?? []).map((d) => (
              <li key={d.id}>
                {d.reason} · <StatusBadge status={d.status === 'open' ? 'open' : 'closed'} />
              </li>
            ))}
          </ul>
          <div className="mt-2 flex flex-wrap items-end gap-2">
            <TextField label="Motif du litige" value={dispute} onChange={(e) => setDispute(e.target.value)} />
            <Button variant="secondary" disabled={dispute === ''} onClick={() => action.mutate('dispute')}>
              Ouvrir un litige
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
