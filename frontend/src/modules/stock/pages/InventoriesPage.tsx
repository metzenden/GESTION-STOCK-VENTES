import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ScanBarcode } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, ApiError, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { useWarehouseNames, useWarehouses } from '@/modules/stock/hooks';

type Line = NonNullable<components['schemas']['Inventory']['lines']>[number];

export function InventoriesPage() {
  const canCreate = useCan('inventories.create');
  const navigate = useNavigate();
  const warehouses = useWarehouses();
  const warehouseName = useWarehouseNames();
  const [warehouse, setWarehouse] = useState('');
  const inventories = useQuery({
    queryKey: ['inventories'],
    queryFn: async () => (await api.GET('/inventories', { params: { query: { per_page: 50 } } })).data,
  });
  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/inventories', {
          body: {
            warehouse_id:
              warehouse !== '' ? warehouse : (warehouses.data?.find((w) => w.is_default)?.id ?? ''),
          },
        })
      ).data?.data,
    onSuccess: (inventory) => inventory !== undefined && void navigate(`/stock/inventaires/${inventory.id}`),
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Inventaires"
        description="Comptage physique : les quantités théoriques sont figées au démarrage, les ventes restent possibles pendant le comptage."
      />
      {canCreate && (
        <Card title="Nouvel inventaire complet">
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-60">
              <SelectField
                label="Dépôt"
                value={warehouse !== '' ? warehouse : (warehouses.data?.find((w) => w.is_default)?.id ?? '')}
                onChange={(e) => setWarehouse(e.target.value)}
              >
                {(warehouses.data ?? [])
                  .filter((w) => w.status === 'active')
                  .map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
              </SelectField>
            </div>
            <Button onClick={() => create.mutate()} loading={create.isPending}>
              Créer
            </Button>
          </div>
          <div className="mt-2">
            <ErrorAlert error={create.error} />
          </div>
        </Card>
      )}
      <Card>
        {inventories.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(inventories.data?.data ?? []).map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/stock/inventaires/${i.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {i.number}
                  </Link>{' '}
                  · {warehouseName(i.warehouse_id)} · {i.scope === 'full' ? 'complet' : 'partiel'} ·{' '}
                  {formatDateTime(i.created_at)}
                </span>
                <StatusBadge status={i.status} />
              </li>
            ))}
            {inventories.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucun inventaire.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

function CountInput({
  inventoryId,
  line,
  editable,
  inputRef,
}: {
  inventoryId: string;
  line: Line;
  editable: boolean;
  inputRef?: (el: HTMLInputElement | null) => void;
}) {
  const queryClient = useQueryClient();
  const [value, setValue] = useState(line.qty_counted === null ? '' : String(line.qty_counted));
  const save = useMutation({
    mutationFn: async (qty: number) =>
      api.PUT('/inventories/{id}/lines/{lineId}', {
        params: { path: { id: inventoryId, lineId: line.id } },
        body: { qty_counted: qty },
      }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['inventory', inventoryId] }),
  });
  const commit = () => {
    if (value !== '' && value !== String(line.qty_counted ?? '')) save.mutate(Number.parseInt(value, 10));
  };

  return (
    <input
      ref={inputRef}
      aria-label={`Quantité comptée ${variantLabel(line.variant)}`}
      type="number"
      inputMode="numeric"
      min={0}
      disabled={!editable}
      className={`w-24 rounded-md border bg-white px-2 py-1.5 text-right text-base sm:text-sm dark:bg-slate-900 ${save.isError ? 'border-red-600' : 'border-slate-300 dark:border-slate-700'}`}
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => e.key === 'Enter' && commit()}
    />
  );
}

export function InventoryDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canCount = useCan('inventories.create');
  const canValidate = useCan('adjustments.create');
  const warehouseName = useWarehouseNames();
  const keys = useRef({ start: newIdempotencyKey(), validate: newIdempotencyKey() });
  const inputs = useRef(new Map<string, HTMLInputElement>());
  const [scan, setScan] = useState('');
  const [scanMessage, setScanMessage] = useState<string | null>(null);
  const inventory = useQuery({
    queryKey: ['inventory', id],
    queryFn: async () => (await api.GET('/inventories/{id}', { params: { path: { id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['inventory', id] });
    void queryClient.invalidateQueries({ queryKey: ['inventories'] });
    void queryClient.invalidateQueries({ queryKey: ['stock'] });
  };
  const action = useMutation({
    mutationFn: async (name: 'start' | 'close-counting' | 'reopen' | 'validate' | 'cancel') => {
      const path = { path: { id } };
      switch (name) {
        case 'start':
          return api.POST('/inventories/{id}/start', {
            params: { ...path, header: { 'Idempotency-Key': keys.current.start } },
          });
        case 'validate':
          return api.POST('/inventories/{id}/validate', {
            params: { ...path, header: { 'Idempotency-Key': keys.current.validate } },
            body: {},
          });
        case 'close-counting':
          return api.POST('/inventories/{id}/close-counting', { params: path });
        case 'reopen':
          return api.POST('/inventories/{id}/reopen', { params: path });
        default:
          return api.POST('/inventories/{id}/cancel', { params: path });
      }
    },
    onSuccess: refresh,
  });

  // Lecteur de code-barres : il saisit le code puis « Entrée » ; on place le curseur sur la ligne de l'article.
  const lookup = async (event: FormEvent) => {
    event.preventDefault();
    setScanMessage(null);
    try {
      const found = (
        await api.GET('/product-variants/lookup', { params: { query: { barcode: scan.trim() } } })
      ).data?.data;
      const lines = (inventory.data?.lines ?? []).filter((l) => l.variant?.id === found?.variant.id);
      const target = lines.find((l) => l.qty_counted === null) ?? lines[0];
      if (target === undefined) setScanMessage('Cet article ne figure pas dans l’inventaire.');
      else inputs.current.get(target.id)?.focus();
    } catch (error) {
      setScanMessage(
        error instanceof ApiError && error.status === 404 ? 'Code-barres inconnu.' : 'Recherche impossible.',
      );
    }
    setScan('');
  };

  if (inventory.isPending) return <Spinner />;
  if (inventory.data === undefined) return <ErrorAlert error={inventory.error} />;
  const inv = inventory.data;
  const lines = inv.lines ?? [];
  const counted = lines.filter((l) => l.qty_counted !== null).length;

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/stock/inventaires" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Inventaires
      </Link>
      <PageHeader
        title={`Inventaire ${inv.number}`}
        description={`${warehouseName(inv.warehouse_id)}${inv.snapshot_at !== null ? ` · figé le ${formatDateTime(inv.snapshot_at)}` : ''} · ${counted}/${lines.length} ligne(s) comptée(s)`}
        actions={
          <div className="flex items-center gap-2">
            <StatusBadge status={inv.status} />
            {inv.status !== 'draft' && (
              <a
                href={`/api/v1/inventories/${inv.id}/sheet.pdf`}
                target="_blank"
                rel="noreferrer"
                className="text-sm text-brand-700 underline dark:text-brand-500"
              >
                Feuille PDF
              </a>
            )}
          </div>
        }
      />
      <ErrorAlert error={action.error} />
      {inv.status === 'validated' && inv.adjustment_id !== null && (
        <Alert tone="success">
          Inventaire validé : les écarts ont produit l’ajustement{' '}
          <Link to={`/stock/ajustements/${inv.adjustment_id}`} className="underline">
            correspondant
          </Link>
          .
        </Alert>
      )}
      {canCount && (
        <div className="flex flex-wrap gap-2">
          {inv.status === 'draft' && (
            <Button onClick={() => action.mutate('start')}>Démarrer le comptage</Button>
          )}
          {inv.status === 'counting' && (
            <Button onClick={() => action.mutate('close-counting')}>Clôturer le comptage</Button>
          )}
          {inv.status === 'review' && (
            <Button variant="secondary" onClick={() => action.mutate('reopen')}>
              Rouvrir le comptage
            </Button>
          )}
          {inv.status === 'review' && canValidate && (
            <Button onClick={() => action.mutate('validate')}>Valider et ajuster le stock</Button>
          )}
          {(inv.status === 'draft' || inv.status === 'counting') && (
            <Button variant="ghost" onClick={() => action.mutate('cancel')}>
              Annuler
            </Button>
          )}
        </div>
      )}
      {inv.status === 'counting' && (
        <form onSubmit={lookup} className="flex items-center gap-2">
          <ScanBarcode className="size-5 text-slate-500" aria-hidden />
          <input
            aria-label="Code-barres"
            placeholder="Scanner ou saisir un code-barres"
            className="flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-base sm:text-sm dark:border-slate-700 dark:bg-slate-900"
            value={scan}
            onChange={(e) => setScan(e.target.value)}
          />
          {scanMessage !== null && <span className="text-sm text-amber-700">{scanMessage}</span>}
        </form>
      )}
      {lines.length > 0 && (
        <Card>
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {lines.map((l) => (
              <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <div className="min-w-0 text-sm">
                  <p className="font-medium">{variantLabel(l.variant)}</p>
                  <p className="text-xs text-slate-500">
                    Lot {l.lot_number ?? '—'}
                    {inv.status !== 'counting' &&
                      ` · théorique ${l.qty_expected}${l.qty_moved_since_snapshot !== 0 ? ` (${l.qty_moved_since_snapshot > 0 ? '+' : ''}${l.qty_moved_since_snapshot} depuis)` : ''}`}
                    {l.difference !== null && l.difference !== 0 && inv.status !== 'counting' && (
                      <strong className="ml-1 text-red-700 dark:text-red-400">
                        écart {l.difference > 0 ? '+' : ''}
                        {l.difference}
                      </strong>
                    )}
                  </p>
                </div>
                <CountInput
                  key={`${l.id}-${l.qty_counted ?? ''}`}
                  inventoryId={inv.id}
                  line={l}
                  editable={canCount && inv.status === 'counting'}
                  inputRef={(el) => {
                    if (el !== null) inputs.current.set(l.id, el);
                  }}
                />
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}
