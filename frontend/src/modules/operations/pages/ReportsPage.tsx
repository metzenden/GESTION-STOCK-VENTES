import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/api/client';
import { Spinner } from '@/components/ui/Badge';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';

/** Rapports (L6-06) : seuls ceux autorisés sont proposés ; export CSV soumis à la même permission. */
export function ReportsPage() {
  const today = new Date().toISOString().slice(0, 10);
  const [report, setReport] = useState('');
  const [from, setFrom] = useState(`${today.slice(0, 8)}01`);
  const [to, setTo] = useState(today);
  const catalog = useQuery({
    queryKey: ['reports'],
    queryFn: async () => (await api.GET('/reports')).data?.data ?? [],
  });
  const rows = useQuery({
    queryKey: ['report', report, from, to],
    enabled: report !== '',
    queryFn: async () => {
      const res: unknown = (
        await api.GET('/reports/{report}', { params: { path: { report }, query: { from, to } } })
      ).data;
      return typeof res === 'object' && res !== null ? (res as { data: Record<string, unknown>[] }).data : [];
    },
  });
  const columns = ((catalog.data ?? []).find((r) => r.code === report)?.columns ?? []) as string[];

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <PageHeader
        title="Rapports"
        description="Montants en devise des documents ; aucune conversion implicite."
      />
      <Card>
        <div className="grid gap-3 sm:grid-cols-4">
          <SelectField label="Rapport" value={report} onChange={(e) => setReport(e.target.value)}>
            <option value="">Choisir…</option>
            {(catalog.data ?? []).map((r) => (
              <option key={String(r.code)} value={String(r.code)}>
                {String(r.label)}
              </option>
            ))}
          </SelectField>
          <TextField label="Du" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          <TextField label="Au" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          {report !== '' && (
            <a
              className="self-end rounded-md border px-3 py-2 text-center text-sm"
              href={`/api/v1/reports/${report}?from=${from}&to=${to}&format=csv`}
            >
              Exporter en CSV
            </a>
          )}
        </div>
      </Card>
      {report !== '' && (
        <Card>
          {rows.isPending ? (
            <Spinner />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-slate-500">
                  <tr>
                    {columns.map((c) => (
                      <th key={c} className="py-1 pr-3">
                        {c.replace(/_/g, ' ')}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                  {(rows.data ?? []).map((r, i) => (
                    <tr key={i}>
                      {columns.map((c) => (
                        <td key={c} className="py-1.5 pr-3">
                          {String((r as Record<string, unknown>)[c] ?? '')}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
