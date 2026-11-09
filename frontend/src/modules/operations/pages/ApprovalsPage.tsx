import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { formatDateTime } from '@/lib/format';
import { useCan } from '@/modules/auth/session';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

/** Demandes d'approbation (L6-11) : décision avec commentaire ; l'exécution est atomique côté serveur. */
export function ApprovalsPage() {
  const queryClient = useQueryClient();
  const canApprove = useCan('approvals.approve');
  const [status, setStatus] = useState('pending');
  const [comment, setComment] = useState<Record<string, string>>({});
  const list = useQuery({
    queryKey: ['approvals', status],
    queryFn: async () =>
      (
        await api.GET('/approvals', {
          params: { query: { per_page: 50, ...(status !== '' ? { 'filter[status]': status } : {}) } },
        })
      ).data,
  });
  const decide = useMutation({
    mutationFn: async ({ id, approve }: { id: string; approve: boolean }) => {
      const params = { path: { approval: id }, header: { 'Idempotency-Key': newIdempotencyKey() } };
      const body = { comment: comment[id] ?? null };
      return approve
        ? api.POST('/approvals/{approval}/approve', { params, body })
        : api.POST('/approvals/{approval}/reject', { params, body });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['approvals'] });
      void queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader
        title="Approbations"
        description="Remises, prix modifiés, annulations, ajustements, avoirs et corrections financières soumis à règle."
      />
      <div className="flex gap-2 text-sm">
        {[
          ['pending', 'En attente'],
          ['approved', 'Approuvées'],
          ['rejected', 'Rejetées'],
          ['', 'Toutes'],
        ].map(([value, label]) => (
          <button
            key={value}
            type="button"
            className={`rounded-md border px-3 py-1.5 ${status === value ? 'border-brand-600 text-brand-700' : 'border-slate-300'}`}
            onClick={() => setStatus(value ?? '')}
          >
            {label}
          </button>
        ))}
      </div>
      <ErrorAlert error={decide.error} />
      <Card>
        {list.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(list.data?.data ?? []).map((r) => {
              const id = String(r.id);
              return (
                <li key={id} className="space-y-2 py-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      <strong>{String(r.number)}</strong> · {String(r.summary)}
                    </span>
                    <StatusBadge status={String(r.status)} />
                  </div>
                  <p className="text-xs text-slate-500">Demandée le {formatDateTime(String(r.created_at))}</p>
                  {r.last_error !== null && (
                    <p className="text-xs text-red-700">Dernier essai : {String(r.last_error)}</p>
                  )}
                  {canApprove && r.status === 'pending' && (
                    <div className="flex flex-wrap items-center gap-2">
                      <input
                        aria-label="Commentaire"
                        placeholder="Commentaire"
                        className="min-w-48 flex-1 rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900"
                        value={comment[id] ?? ''}
                        onChange={(e) => setComment({ ...comment, [id]: e.target.value })}
                      />
                      <Button onClick={() => decide.mutate({ id, approve: true })}>Approuver</Button>
                      <Button variant="danger" onClick={() => decide.mutate({ id, approve: false })}>
                        Rejeter
                      </Button>
                    </div>
                  )}
                </li>
              );
            })}
            {(list.data?.data ?? []).length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucune demande.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}
