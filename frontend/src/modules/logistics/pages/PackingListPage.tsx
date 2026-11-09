import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { useCan } from '@/modules/auth/session';
import { variantLabel, VariantPicker } from '@/modules/catalog/components/VariantPicker';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

type Line = components['schemas']['PackingListLine'];

const matchLabels: Record<string, { label: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }> = {
  unmatched: { label: 'À rattacher', tone: 'danger' },
  suggested: { label: 'Suggestion', tone: 'warning' },
  confirmed: { label: 'Rattachée', tone: 'success' },
  new_product: { label: 'Nouveau produit', tone: 'success' },
  ignored: { label: 'Écartée', tone: 'neutral' },
};

function ReviewRow({ listId, line, editable }: { listId: string; line: Line; editable: boolean }) {
  const queryClient = useQueryClient();
  const [picking, setPicking] = useState(false);
  const review = useMutation({
    mutationFn: async (body: Record<string, unknown>) =>
      api.PATCH('/packing-lists/{list}/lines/{line}', {
        params: { path: { list: listId, line: line.id } },
        body,
      }),
    onSuccess: () => {
      setPicking(false);
      void queryClient.invalidateQueries({ queryKey: ['packing-list', listId] });
    },
  });
  const status = matchLabels[line.match_status] ?? { label: line.match_status, tone: 'neutral' as const };

  return (
    <li className="space-y-1 py-3 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p>
            <span className="text-slate-500">L{line.row_number}</span>{' '}
            <strong>{line.supplier_reference ?? '—'}</strong> · {line.description ?? ''}
          </p>
          <p className="text-xs text-slate-600 dark:text-slate-400">
            {line.cartons !== null && `${line.cartons} ctn × ${line.pieces_per_carton ?? '?'} · `}
            {line.total_qty ?? '?'} pièces · CBM {line.cbm ?? '—'}
            {line.unit_price !== undefined && line.unit_price !== null && ` · PU ${line.unit_price}`}
          </p>
          {line.matched_variant !== null && <p className="text-xs">→ {variantLabel(line.matched_variant)}</p>}
          {line.errors.map((e) => (
            <p key={e.field + e.message} className="text-xs text-red-700">
              {e.message}
            </p>
          ))}
        </div>
        <Badge tone={status.tone}>{status.label}</Badge>
      </div>
      {editable && (
        <div className="flex flex-wrap gap-2">
          {line.matched_variant !== null && line.match_status !== 'confirmed' && (
            <Button variant="secondary" onClick={() => review.mutate({ action: 'confirm' })}>
              Confirmer le rattachement
            </Button>
          )}
          <Button variant="secondary" onClick={() => setPicking(!picking)}>
            Choisir un article
          </Button>
          {line.match_status !== 'new_product' && (
            <Button variant="secondary" onClick={() => review.mutate({ action: 'new_product' })}>
              Créer le produit
            </Button>
          )}
          {line.match_status !== 'ignored' ? (
            <Button variant="ghost" onClick={() => review.mutate({ action: 'ignore' })}>
              Écarter
            </Button>
          ) : (
            <Button variant="ghost" onClick={() => review.mutate({ action: 'reset' })}>
              Reprendre
            </Button>
          )}
        </div>
      )}
      {picking && (
        <VariantPicker
          label="Article correspondant"
          onSelect={(v) => review.mutate({ action: 'confirm', variant_id: v.id })}
        />
      )}
      <ErrorAlert error={review.error} />
    </li>
  );
}

export function PackingListPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canManage = useCan('shipments.manage');
  const validateKey = useRef(newIdempotencyKey());
  const list = useQuery({
    queryKey: ['packing-list', id],
    queryFn: async () =>
      (await api.GET('/packing-lists/{list}', { params: { path: { list: id } } })).data?.data,
  });
  const action = useMutation({
    mutationFn: async (name: 'validate' | 'reject') =>
      name === 'validate'
        ? api.POST('/packing-lists/{list}/validate', {
            params: { path: { list: id }, header: { 'Idempotency-Key': validateKey.current } },
          })
        : api.POST('/packing-lists/{list}/reject', { params: { path: { list: id } } }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['packing-list', id] });
      void queryClient.invalidateQueries({ queryKey: ['shipment'] });
    },
  });

  if (list.isPending) return <Spinner />;
  if (list.data === undefined) return <ErrorAlert error={list.error} />;
  const pl = list.data;
  const lines = pl.lines ?? [];
  const pending = lines.filter(
    (l) => !['confirmed', 'new_product', 'ignored'].includes(l.match_status),
  ).length;
  const editable = canManage && pl.status === 'in_review';

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link
        to={`/arrivages/${pl.shipment_id}`}
        className="text-sm text-brand-700 underline dark:text-brand-500"
      >
        ← Arrivage
      </Link>
      <PageHeader
        title="Revue de la packing list"
        description={`${lines.length} ligne(s) · devise ${pl.currency} · l’import ne modifie jamais le stock.`}
        actions={<StatusBadge status={pl.status} />}
      />
      <ErrorAlert error={action.error} />
      <Card title={pending > 0 ? `${pending} ligne(s) à traiter` : 'Toutes les lignes sont traitées'}>
        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
          {lines.map((l) => (
            <ReviewRow key={l.id} listId={pl.id} line={l} editable={editable} />
          ))}
        </ul>
      </Card>
      {editable && (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => action.mutate('validate')} disabled={pending > 0} loading={action.isPending}>
            Valider : alimenter les articles attendus
          </Button>
          <Button variant="ghost" onClick={() => action.mutate('reject')}>
            Rejeter
          </Button>
        </div>
      )}
    </div>
  );
}
