import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { formatDateTime } from '@/lib/format';

export function AuditPage() {
  const [page, setPage] = useState(1);
  const logs = useQuery({
    queryKey: ['audit', page],
    queryFn: async () => (await api.GET('/audit-logs', { params: { query: { page, per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Journal d'audit"
        description="Actions sensibles et modifications des données de référence. Le journal ne peut être ni modifié ni effacé."
      />
      <Card>
        {logs.isPending ? (
          <Spinner />
        ) : logs.isError ? (
          <ErrorAlert error={logs.error} />
        ) : (
          <>
            <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
              {logs.data?.data.map((log) => (
                <li key={log.id} className="py-2">
                  <div className="flex flex-wrap justify-between gap-2">
                    <span className="font-mono text-xs">{log.action}</span>
                    <span className="text-xs text-slate-500">{formatDateTime(log.occurred_at)}</span>
                  </div>
                  {log.new_values !== null && (
                    <p className="mt-1 truncate text-xs text-slate-500">{JSON.stringify(log.new_values)}</p>
                  )}
                </li>
              ))}
            </ul>
            <div className="mt-3 flex items-center justify-between text-sm">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Précédent
              </Button>
              <span>
                Page {logs.data?.meta.page} / {logs.data?.meta.last_page}
              </span>
              <Button
                variant="secondary"
                disabled={page >= (logs.data?.meta.last_page ?? 1)}
                onClick={() => setPage(page + 1)}
              >
                Suivant
              </Button>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
