import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { useCan } from '@/modules/auth/session';
import { variantLabel, VariantPicker, type VariantRef } from '@/modules/catalog/components/VariantPicker';
import { LotSelect } from '@/modules/stock/components/LotSelect';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouseNames, useWarehouses } from '@/modules/stock/hooks';

interface DraftLine {
  variant: VariantRef;
  direction: 'in' | 'out';
  quantity: string;
  lot_id: string;
  unit_cost: string;
  kind: 'gain' | 'loss' | 'damage' | 'correction';
}

function NewAdjustment({ onCancel }: { onCancel: () => void }) {
  const navigate = useNavigate();
  const warehouses = useWarehouses();
  const [warehouse, setWarehouse] = useState('');
  const [reason, setReason] = useState('');
  const [lines, setLines] = useState<DraftLine[]>([]);
  const warehouseId = warehouse !== '' ? warehouse : (warehouses.data?.find((w) => w.is_default)?.id ?? '');

  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/stock-adjustments', {
          body: {
            warehouse_id: warehouseId,
            reason: reason === '' ? null : reason,
            lines: lines.map((l) => {
              const qty = Number.parseInt(l.quantity || '0', 10);
              return l.direction === 'in'
                ? {
                    variant_id: l.variant.id,
                    quantity: qty,
                    kind: l.kind,
                    unit_cost: l.unit_cost === '' ? null : l.unit_cost,
                  }
                : { variant_id: l.variant.id, quantity: -qty, kind: l.kind, lot_id: l.lot_id };
            }),
          },
        })
      ).data?.data,
    onSuccess: (adjustment) =>
      adjustment !== undefined && void navigate(`/stock/ajustements/${adjustment.id}`),
  });

  const update = (index: number, patch: Partial<DraftLine>) =>
    setLines(lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  return (
    <Card title="Nouvel ajustement">
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <SelectField label="Dépôt" value={warehouseId} onChange={(e) => setWarehouse(e.target.value)}>
            {(warehouses.data ?? [])
              .filter((w) => w.status === 'active')
              .map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
          </SelectField>
          <TextField label="Motif" value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        <VariantPicker
          label="Ajouter un article"
          onSelect={(variant) =>
            setLines([
              ...lines,
              { variant, direction: 'out', quantity: '1', lot_id: '', unit_cost: '', kind: 'loss' },
            ])
          }
        />
        {lines.map((line, index) => (
          <div
            key={index}
            className="grid gap-2 rounded-md border border-slate-200 p-3 sm:grid-cols-[1fr_8rem_6rem_12rem_auto] sm:items-center dark:border-slate-800"
          >
            <span className="text-sm">{variantLabel(line.variant)}</span>
            <select
              aria-label="Sens"
              className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
              value={line.direction}
              onChange={(e) =>
                update(index, {
                  direction: e.target.value as 'in' | 'out',
                  kind: e.target.value === 'in' ? 'gain' : 'loss',
                })
              }
            >
              <option value="out">Sortie</option>
              <option value="in">Entrée</option>
            </select>
            <input
              aria-label="Quantité"
              type="number"
              min={1}
              className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
              value={line.quantity}
              onChange={(e) => update(index, { quantity: e.target.value })}
            />
            {line.direction === 'out' ? (
              <LotSelect
                variantId={line.variant.id}
                warehouseId={warehouseId}
                value={line.lot_id}
                onChange={(lot_id) => update(index, { lot_id })}
              />
            ) : (
              <input
                aria-label="Coût unitaire"
                placeholder="Coût (facultatif)"
                inputMode="decimal"
                className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
                value={line.unit_cost}
                onChange={(e) => update(index, { unit_cost: e.target.value.replace(',', '.') })}
              />
            )}
            <button
              type="button"
              aria-label="Retirer la ligne"
              className="justify-self-end p-1 text-red-700"
              onClick={() => setLines(lines.filter((_, i) => i !== index))}
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        ))}
        <ErrorAlert error={create.error} />
        <div className="flex gap-2">
          <Button onClick={() => create.mutate()} loading={create.isPending} disabled={lines.length === 0}>
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

export function AdjustmentsPage() {
  const canCreate = useCan('adjustments.create');
  const warehouseName = useWarehouseNames();
  const [creating, setCreating] = useState(false);
  const adjustments = useQuery({
    queryKey: ['adjustments'],
    queryFn: async () => (await api.GET('/stock-adjustments', { params: { query: { per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Ajustements de stock"
        description="Corrections de stock : une entrée crée un lot, une sortie porte sur un lot précis."
        actions={
          canCreate && !creating && <Button onClick={() => setCreating(true)}>Nouvel ajustement</Button>
        }
      />
      {creating && <NewAdjustment onCancel={() => setCreating(false)} />}
      <Card>
        {adjustments.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(adjustments.data?.data ?? []).map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/stock/ajustements/${a.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {a.number}
                  </Link>{' '}
                  · {warehouseName(a.warehouse_id)} · {a.reason ?? 'sans motif'} ·{' '}
                  {formatDateTime(a.created_at)}
                </span>
                <StatusBadge status={a.status} />
              </li>
            ))}
            {adjustments.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucun ajustement.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

export function AdjustmentDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canCreate = useCan('adjustments.create');
  const warehouseName = useWarehouseNames();
  const submitKey = useRef(newIdempotencyKey());
  const adjustment = useQuery({
    queryKey: ['adjustment', id],
    queryFn: async () => (await api.GET('/stock-adjustments/{id}', { params: { path: { id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['adjustment', id] });
    void queryClient.invalidateQueries({ queryKey: ['adjustments'] });
    void queryClient.invalidateQueries({ queryKey: ['stock'] });
  };
  const submit = useMutation({
    mutationFn: async () =>
      api.POST('/stock-adjustments/{id}/submit', {
        params: { path: { id }, header: { 'Idempotency-Key': submitKey.current } },
      }),
    onSuccess: refresh,
  });
  const cancel = useMutation({
    mutationFn: async () => api.POST('/stock-adjustments/{id}/cancel', { params: { path: { id } } }),
    onSuccess: refresh,
  });

  if (adjustment.isPending) return <Spinner />;
  if (adjustment.data === undefined) return <ErrorAlert error={adjustment.error} />;
  const a = adjustment.data;

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/stock/ajustements" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Ajustements
      </Link>
      <PageHeader
        title={`Ajustement ${a.number}`}
        description={`${warehouseName(a.warehouse_id)} · ${a.reason ?? 'sans motif'}`}
        actions={<StatusBadge status={a.status} />}
      />
      {a.status === 'pending_approval' && (
        <Alert tone="warning">
          En attente d’approbation : les quantités à sortir sont bloquées et ne peuvent pas être vendues.
        </Alert>
      )}
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(a.lines ?? []).map((l) => (
            <li key={l.id} className="flex flex-wrap justify-between gap-2 py-2">
              <span>{variantLabel(l.variant)}</span>
              <span>
                <strong
                  className={
                    l.quantity < 0
                      ? 'text-red-700 dark:text-red-400'
                      : 'text-emerald-700 dark:text-emerald-400'
                  }
                >
                  {l.quantity > 0 ? '+' : ''}
                  {l.quantity}
                </strong>{' '}
                · lot {l.lot_number ?? 'nouveau'}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      {canCreate && a.status === 'draft' && (
        <div className="space-y-2">
          <ErrorAlert error={submit.error ?? cancel.error} />
          <div className="flex gap-2">
            <Button onClick={() => submit.mutate()} loading={submit.isPending}>
              Comptabiliser
            </Button>
            <Button variant="ghost" onClick={() => cancel.mutate()}>
              Annuler l’ajustement
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
