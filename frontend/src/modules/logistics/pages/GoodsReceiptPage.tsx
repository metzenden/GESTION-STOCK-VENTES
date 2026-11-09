import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouseNames } from '@/modules/stock/hooks';

type ReceiptLine = components['schemas']['GoodsReceiptLine'];

const input =
  'w-20 rounded-md border border-slate-300 bg-white px-2 py-2 text-base dark:border-slate-700 dark:bg-slate-900';

function CountRow({
  receiptId,
  line,
  editable,
}: {
  receiptId: string;
  line: ReceiptLine;
  editable: boolean;
}) {
  const queryClient = useQueryClient();
  const [accepted, setAccepted] = useState(String(line.qty_accepted));
  const [damaged, setDamaged] = useState(String(line.qty_damaged));
  const save = useMutation({
    mutationFn: async () =>
      api.PUT('/goods-receipts/{receipt}/lines/{line}', {
        params: { path: { receipt: receiptId, line: line.id } },
        body: {
          qty_accepted: Number.parseInt(accepted || '0', 10),
          qty_damaged: Number.parseInt(damaged || '0', 10),
        },
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['goods-receipt', receiptId] }),
  });

  return (
    <li className="space-y-2 py-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium">{variantLabel(line.variant)}</span>
        <span>
          attendu <strong>{line.qty_expected}</strong> · compté <strong>{line.qty_counted}</strong>
          {line.qty_missing > 0 && line.is_counted && (
            <span className="text-red-700"> · manque {line.qty_missing}</span>
          )}
          {line.qty_surplus > 0 && <span className="text-amber-700"> · surplus {line.qty_surplus}</span>}
          {line.lot_number !== null && <span className="text-slate-500"> · lot {line.lot_number}</span>}
        </span>
      </div>
      {editable && (
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-1">
            Bon état{' '}
            <input
              aria-label="Quantité acceptée"
              type="number"
              min={0}
              inputMode="numeric"
              className={input}
              value={accepted}
              onChange={(e) => setAccepted(e.target.value)}
            />
          </label>
          <label className="flex items-center gap-1">
            Endommagé{' '}
            <input
              aria-label="Quantité endommagée"
              type="number"
              min={0}
              inputMode="numeric"
              className={input}
              value={damaged}
              onChange={(e) => setDamaged(e.target.value)}
            />
          </label>
          <Button variant="secondary" onClick={() => save.mutate()} loading={save.isPending}>
            Enregistrer
          </Button>
          <ErrorAlert error={save.error} />
        </div>
      )}
    </li>
  );
}

export function GoodsReceiptPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canManage = useCan('shipments.manage');
  const warehouseName = useWarehouseNames();
  const validateKey = useRef(newIdempotencyKey());
  const [code, setCode] = useState('');
  const [damagedScan, setDamagedScan] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const receipt = useQuery({
    queryKey: ['goods-receipt', id],
    queryFn: async () =>
      (await api.GET('/goods-receipts/{receipt}', { params: { path: { receipt: id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['goods-receipt', id] });
    void queryClient.invalidateQueries({ queryKey: ['shipment'] });
  };
  const scan = useMutation({
    // Un identifiant par lecture : un renvoi réseau du même scan n'est compté qu'une fois (IMP-010).
    mutationFn: async (value: string) =>
      (
        await api.POST('/goods-receipts/{receipt}/scans', {
          params: { path: { receipt: id } },
          body: { client_event_id: newIdempotencyKey(), code: value, damaged: damagedScan },
        })
      ).data?.data,
    onSuccess: (event) => {
      setCode('');
      setMessage(
        event?.outcome === 'counted'
          ? `+${event.qty_base} compté${damagedScan ? ' (endommagé)' : ''}`
          : event?.outcome === 'not_expected'
            ? 'Article non attendu dans cette réception.'
            : 'Code-barres inconnu.',
      );
      refresh();
    },
  });
  const action = useMutation({
    mutationFn: async (name: 'validate' | 'cancel') =>
      name === 'validate'
        ? api.POST('/goods-receipts/{receipt}/validate', {
            params: { path: { receipt: id }, header: { 'Idempotency-Key': validateKey.current } },
          })
        : api.POST('/goods-receipts/{receipt}/cancel', { params: { path: { receipt: id } } }),
    onSuccess: refresh,
  });

  if (receipt.isPending) return <Spinner />;
  if (receipt.data === undefined) return <ErrorAlert error={receipt.error} />;
  const r = receipt.data;
  const editable = canManage && (r.status === 'draft' || r.status === 'counting');

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link
        to={
          r.shipment_id !== null
            ? `/arrivages/${r.shipment_id}`
            : `/achats/commandes/${r.purchase_order_id ?? ''}`
        }
        className="text-sm text-brand-700 underline dark:text-brand-500"
      >
        ← Retour
      </Link>
      <PageHeader
        title={`Réception ${r.number}`}
        description={`Dépôt : ${warehouseName(r.warehouse_id)} · le comptage ne modifie pas le stock avant validation.`}
        actions={<StatusBadge status={r.status} />}
      />
      {editable && (
        <Card title="Lecteur de code-barres">
          <div className="flex flex-wrap items-center gap-2">
            <input
              aria-label="Code-barres"
              autoFocus
              placeholder="Scanner ou saisir puis Entrée"
              className="min-w-56 flex-1 rounded-md border border-slate-300 bg-white px-3 py-3 text-base dark:border-slate-700 dark:bg-slate-900"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && code.trim() !== '' && scan.mutate(code.trim())}
            />
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={damagedScan}
                onChange={(e) => setDamagedScan(e.target.checked)}
              />{' '}
              Endommagé
            </label>
          </div>
          {message !== null && <p className="mt-2 text-sm">{message}</p>}
          <ErrorAlert error={scan.error} />
        </Card>
      )}
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
          {(r.lines ?? []).map((l) => (
            <CountRow key={`${l.id}-${l.qty_counted}`} receiptId={r.id} line={l} editable={editable} />
          ))}
        </ul>
      </Card>
      <ErrorAlert error={action.error} />
      {editable && (
        <div className="flex flex-wrap gap-2">
          <Button
            onClick={() => action.mutate('validate')}
            loading={action.isPending}
            disabled={r.status !== 'counting'}
          >
            Valider la réception (entrée en stock)
          </Button>
          <Button variant="ghost" onClick={() => action.mutate('cancel')}>
            Annuler
          </Button>
        </div>
      )}
    </div>
  );
}
