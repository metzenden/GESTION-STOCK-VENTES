import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { ShipmentCosts } from '@/modules/logistics/pages/ShipmentCosts';
import { decimalInput, unitPrice } from '@/modules/purchasing/hooks';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouseNames, useWarehouses } from '@/modules/stock/hooks';

type Shipment = components['schemas']['Shipment'];
type ShipmentLine = components['schemas']['ShipmentLine'];

const input =
  'rounded-md border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-900';

/** Étapes logistiques manuelles proposées selon l'état (docs/04 §6). */
const NEXT: Record<
  string,
  { to: 'shipped' | 'in_transit' | 'arrived' | 'received' | 'closed' | 'cancelled'; label: string }[]
> = {
  preparing: [
    { to: 'shipped', label: 'Marquer expédié' },
    { to: 'cancelled', label: 'Annuler' },
  ],
  shipped: [
    { to: 'in_transit', label: 'Marquer en transit' },
    { to: 'arrived', label: 'Marquer arrivé' },
  ],
  in_transit: [{ to: 'arrived', label: 'Marquer arrivé' }],
  partially_received: [{ to: 'received', label: 'Clôturer le reliquat' }],
  received: [{ to: 'closed', label: 'Clôturer l’arrivage' }],
};

export function ShipmentsPage() {
  const navigate = useNavigate();
  const canManage = useCan('shipments.manage');
  const warehouses = useWarehouses();
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ reference: '', carrier: '', eta: '', default_warehouse_id: '' });
  const shipments = useQuery({
    queryKey: ['shipments'],
    queryFn: async () => (await api.GET('/shipments', { params: { query: { per_page: 50 } } })).data,
  });
  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/shipments', {
          body: {
            reference: form.reference || null,
            carrier: form.carrier || null,
            eta: form.eta || null,
            default_warehouse_id: form.default_warehouse_id || null,
          },
        })
      ).data?.data,
    onSuccess: (s) => s !== undefined && void navigate(`/arrivages/${s.id}`),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Arrivages"
        description="Marchandises attendues (visibles, jamais vendables) jusqu’à la validation des réceptions."
        actions={canManage && !creating && <Button onClick={() => setCreating(true)}>Nouvel arrivage</Button>}
      />
      {creating && (
        <Card title="Nouvel arrivage">
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField
              label="Référence (BL, booking…)"
              value={form.reference}
              onChange={(e) => setForm({ ...form, reference: e.target.value })}
            />
            <TextField
              label="Transporteur"
              value={form.carrier}
              onChange={(e) => setForm({ ...form, carrier: e.target.value })}
            />
            <TextField
              label="Arrivée prévue"
              type="date"
              value={form.eta}
              onChange={(e) => setForm({ ...form, eta: e.target.value })}
            />
            <SelectField
              label="Dépôt de destination"
              value={form.default_warehouse_id}
              onChange={(e) => setForm({ ...form, default_warehouse_id: e.target.value })}
            >
              <option value="">Choisir…</option>
              {(warehouses.data ?? [])
                .filter((w) => w.status === 'active')
                .map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
            </SelectField>
          </div>
          <ErrorAlert error={create.error} />
          <div className="mt-3 flex gap-2">
            <Button
              onClick={() => create.mutate()}
              loading={create.isPending}
              disabled={form.default_warehouse_id === ''}
            >
              Créer
            </Button>
            <Button variant="ghost" onClick={() => setCreating(false)}>
              Annuler
            </Button>
          </div>
        </Card>
      )}
      <Card>
        {shipments.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(shipments.data?.data ?? []).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/arrivages/${s.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {s.number}
                  </Link>{' '}
                  {s.reference !== null && `· ${s.reference} `}
                  {s.eta !== null && <span className="text-slate-500">· prévu le {s.eta}</span>}
                </span>
                <StatusBadge status={s.status} />
              </li>
            ))}
            {shipments.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucun arrivage.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

function LineRow({
  shipment,
  line,
  editable,
}: {
  shipment: Shipment;
  line: ShipmentLine;
  editable: boolean;
}) {
  const queryClient = useQueryClient();
  const [cbm, setCbm] = useState(line.cbm_total ?? '');
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['shipment', shipment.id] });
  const save = useMutation({
    mutationFn: async () =>
      api.PATCH('/shipments/{shipment}/lines/{line}', {
        params: { path: { shipment: shipment.id, line: line.id } },
        body: { cbm_total: cbm === '' ? null : decimalInput(cbm) },
      }),
    onSuccess: refresh,
  });
  const remove = useMutation({
    mutationFn: async () =>
      api.DELETE('/shipments/{shipment}/lines/{line}', {
        params: { path: { shipment: shipment.id, line: line.id } },
      }),
    onSuccess: refresh,
  });

  return (
    <li className="space-y-1 py-2 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>
          {line.position}. {variantLabel(line.variant)}{' '}
          <span className="text-slate-500">
            {line.cartons !== null && `· ${line.cartons} ctn`}
            {line.pieces_per_carton !== null && ` × ${line.pieces_per_carton}`} · {line.supplier?.name ?? ''}
          </span>
        </span>
        <span>
          attendu {line.qty_expected} · reçu {line.qty_received}
          {line.is_closed && line.qty_remaining === 0 && line.qty_received < line.qty_expected && (
            <strong className="text-red-700"> · manquant {line.qty_expected - line.qty_received}</strong>
          )}
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
        {line.purchase_unit_price !== undefined && (
          <span>
            achat {formatMoney(unitPrice(line.purchase_unit_price, line.purchase_currency), { exact: true })}
            /pce · {formatMoney({ amount: line.purchase_amount ?? '0', currency: line.purchase_currency })}
          </span>
        )}
        {editable ? (
          <>
            <input
              aria-label="Volume total (m³)"
              placeholder="CBM total"
              inputMode="decimal"
              className={`${input} w-28`}
              value={cbm}
              onChange={(e) => setCbm(e.target.value)}
            />
            <Button variant="secondary" onClick={() => save.mutate()} loading={save.isPending}>
              Enregistrer le CBM
            </Button>
            <button
              type="button"
              aria-label="Retirer l’article"
              className="p-1 text-red-700"
              onClick={() => remove.mutate()}
            >
              <Trash2 className="size-4" />
            </button>
          </>
        ) : (
          <span>CBM {line.cbm_total ?? '—'}</span>
        )}
        <ErrorAlert error={save.error ?? remove.error} />
      </div>
    </li>
  );
}

export function ShipmentDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const canManage = useCan('shipments.manage');
  const canCost = useCan('costing.view');
  const canSeeOrders = useCan('purchase_orders.view');
  const warehouseName = useWarehouseNames();
  const warehouses = useWarehouses();
  const transitionKey = useRef(newIdempotencyKey());
  const [container, setContainer] = useState('');
  const [orderId, setOrderId] = useState('');
  const [receiptWarehouse, setReceiptWarehouse] = useState('');
  const [file, setFile] = useState<File | null>(null);

  const shipment = useQuery({
    queryKey: ['shipment', id],
    queryFn: async () =>
      (await api.GET('/shipments/{shipment}', { params: { path: { shipment: id } } })).data?.data,
  });
  const orders = useQuery({
    queryKey: ['purchase-orders', 'receivable'],
    enabled: canSeeOrders,
    queryFn: async () =>
      (await api.GET('/purchase-orders', { params: { query: { per_page: 100 } } })).data?.data ?? [],
  });
  const packingLists = useQuery({
    queryKey: ['shipment', id, 'packing-lists'],
    queryFn: async () =>
      (await api.GET('/shipments/{shipment}/packing-lists', { params: { path: { shipment: id } } })).data
        ?.data ?? [],
  });
  const receipts = useQuery({
    queryKey: ['shipment', id, 'receipts'],
    queryFn: async () =>
      (await api.GET('/goods-receipts', { params: { query: { 'filter[shipment_id]': id, per_page: 50 } } }))
        .data?.data ?? [],
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['shipment', id] });
    void queryClient.invalidateQueries({ queryKey: ['shipments'] });
  };
  const transition = useMutation({
    mutationFn: async (to: 'shipped' | 'in_transit' | 'arrived' | 'received' | 'closed' | 'cancelled') => {
      const result = await api.POST('/shipments/{shipment}/transition', {
        params: { path: { shipment: id }, header: { 'Idempotency-Key': transitionKey.current } },
        body: { to },
      });
      transitionKey.current = newIdempotencyKey();
      return result;
    },
    onSuccess: refresh,
  });
  const addContainer = useMutation({
    mutationFn: async () =>
      api.POST('/shipments/{shipment}/containers', {
        params: { path: { shipment: id } },
        body: { container_number: container },
      }),
    onSuccess: () => {
      setContainer('');
      refresh();
    },
  });
  const linkOrder = useMutation({
    mutationFn: async () =>
      api.POST('/shipments/{shipment}/purchase-orders', {
        params: { path: { shipment: id } },
        body: { purchase_order_id: orderId },
      }),
    onSuccess: () => {
      setOrderId('');
      refresh();
    },
  });
  const upload = useMutation({
    mutationFn: async () => {
      const body = new FormData();
      if (file !== null) body.append('file', file);
      const result = await api.POST('/shipments/{shipment}/packing-lists', {
        params: { path: { shipment: id } },
        body: body as unknown as { file: string },
        bodySerializer: (b) => b as unknown as FormData,
      });
      return result.data?.data;
    },
    onSuccess: (list) => list !== undefined && void navigate(`/packing-lists/${list.id}`),
  });
  const openReceipt = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/goods-receipts', {
          body: { shipment_id: id, warehouse_id: receiptWarehouse, counting_mode: 'scan' },
        })
      ).data?.data,
    onSuccess: (r) => r !== undefined && void navigate(`/receptions/${r.id}`),
  });

  if (shipment.isPending) return <Spinner />;
  if (shipment.data === undefined) return <ErrorAlert error={shipment.error} />;
  const s = shipment.data;
  const editable = ['preparing', 'shipped', 'in_transit', 'arrived'].includes(s.status);
  const receivingOpen = ['arrived', 'unloading', 'partially_received'].includes(s.status);
  const destinations = [
    ...new Set((s.lines ?? []).filter((l) => l.qty_remaining > 0).map((l) => l.destination_warehouse_id)),
  ];

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <Link to="/arrivages" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Arrivages
      </Link>
      <PageHeader
        title={`Arrivage ${s.number}`}
        description={[
          s.reference,
          s.carrier,
          s.eta !== null ? `prévu le ${s.eta}` : null,
          s.arrived_at !== null ? `arrivé le ${s.arrived_at}` : null,
        ]
          .filter(Boolean)
          .join(' · ')}
        actions={<StatusBadge status={s.status} />}
      />
      <ErrorAlert error={transition.error} />
      {canManage && (NEXT[s.status] ?? []).length > 0 && (
        <div className="flex flex-wrap gap-2">
          {(NEXT[s.status] ?? []).map((n) => (
            <Button
              key={n.to}
              variant={n.to === 'cancelled' ? 'ghost' : 'primary'}
              onClick={() => transition.mutate(n.to)}
              loading={transition.isPending}
            >
              {n.label}
            </Button>
          ))}
        </div>
      )}

      <Card title="Articles attendus">
        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
          {(s.lines ?? []).map((l) => (
            <LineRow key={l.id} shipment={s} line={l} editable={editable && canManage} />
          ))}
          {(s.lines ?? []).length === 0 && (
            <li className="py-2 text-sm text-slate-500">
              Rattachez une commande ou importez une packing list.
            </li>
          )}
        </ul>
        {editable && canManage && (
          <div className="mt-4 grid gap-3 border-t border-slate-200 pt-4 dark:border-slate-800 sm:grid-cols-2">
            {canSeeOrders && (
              <div className="flex items-end gap-2">
                <SelectField
                  label="Rattacher une commande confirmée"
                  value={orderId}
                  onChange={(e) => setOrderId(e.target.value)}
                >
                  <option value="">Choisir…</option>
                  {(orders.data ?? [])
                    .filter((o) => o.status === 'confirmed' || o.status === 'partially_received')
                    .map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.number} · {o.supplier?.name}
                      </option>
                    ))}
                </SelectField>
                <Button
                  variant="secondary"
                  onClick={() => linkOrder.mutate()}
                  disabled={orderId === ''}
                  loading={linkOrder.isPending}
                >
                  Rattacher
                </Button>
              </div>
            )}
            <div className="flex items-end gap-2">
              <label className="block flex-1 text-sm">
                <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
                  Packing list (Excel ou CSV)
                </span>
                <input
                  type="file"
                  accept=".xlsx,.csv"
                  className="block w-full text-sm"
                  onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                />
              </label>
              <Button
                variant="secondary"
                onClick={() => upload.mutate()}
                disabled={file === null}
                loading={upload.isPending}
              >
                Importer
              </Button>
            </div>
            <ErrorAlert error={linkOrder.error ?? upload.error} />
          </div>
        )}
      </Card>

      <div className="grid gap-4 md:grid-cols-2">
        <Card title="Conteneurs">
          <ul className="text-sm">
            {(s.containers ?? []).map((c) => (
              <li key={c.id}>
                {c.container_number} {c.container_type !== null && `· ${c.container_type}`}
              </li>
            ))}
            {(s.containers ?? []).length === 0 && <li className="text-slate-500">Aucun conteneur.</li>}
          </ul>
          {editable && canManage && (
            <div className="mt-3 flex items-end gap-2">
              <TextField
                label="N° de conteneur"
                value={container}
                onChange={(e) => setContainer(e.target.value)}
              />
              <Button variant="secondary" onClick={() => addContainer.mutate()} disabled={container === ''}>
                Ajouter
              </Button>
            </div>
          )}
          <ErrorAlert error={addContainer.error} />
        </Card>
        <Card title="Packing lists">
          <ul className="text-sm">
            {(packingLists.data ?? []).map((p) => (
              <li key={p.id} className="flex items-center justify-between py-1">
                <Link to={`/packing-lists/${p.id}`} className="text-brand-700 underline dark:text-brand-500">
                  {p.file_format.toUpperCase()} du {formatDateTime(p.created_at)}
                </Link>
                <StatusBadge status={p.status} />
              </li>
            ))}
            {(packingLists.data ?? []).length === 0 && <li className="text-slate-500">Aucune.</li>}
          </ul>
        </Card>
      </div>

      <Card title="Réceptions">
        <ul className="text-sm">
          {(receipts.data ?? []).map((r) => (
            <li key={r.id} className="flex items-center justify-between py-1">
              <Link to={`/receptions/${r.id}`} className="text-brand-700 underline dark:text-brand-500">
                {r.number} · {warehouseName(r.warehouse_id)}
              </Link>
              <StatusBadge status={r.status} />
            </li>
          ))}
        </ul>
        {canManage && receivingOpen && (
          <div className="mt-3 flex flex-wrap items-end gap-2">
            <SelectField
              label="Dépôt de réception"
              value={receiptWarehouse}
              onChange={(e) => setReceiptWarehouse(e.target.value)}
            >
              <option value="">Choisir…</option>
              {(warehouses.data ?? [])
                .filter((w) => destinations.includes(w.id))
                .map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
            </SelectField>
            <Button
              onClick={() => openReceipt.mutate()}
              disabled={receiptWarehouse === ''}
              loading={openReceipt.isPending}
            >
              Ouvrir une réception
            </Button>
          </div>
        )}
        <ErrorAlert error={openReceipt.error} />
      </Card>

      {canCost && <ShipmentCosts shipment={s} />}
    </div>
  );
}
