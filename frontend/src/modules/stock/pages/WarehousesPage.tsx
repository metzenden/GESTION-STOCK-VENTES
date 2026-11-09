import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { api, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';
import { useWarehouses } from '@/modules/stock/hooks';

export function WarehousesPage() {
  const canManage = useCan('warehouses.manage');
  const queryClient = useQueryClient();
  const warehouses = useWarehouses();
  const [form, setForm] = useState({ code: '', name: '', address: '' });
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['warehouses'] });
  const create = useMutation({
    mutationFn: async () =>
      api.POST('/warehouses', {
        body: {
          code: form.code.toUpperCase(),
          name: form.name,
          address: form.address === '' ? null : form.address,
        },
      }),
    onSuccess: () => {
      setForm({ code: '', name: '', address: '' });
      refresh();
    },
  });
  const update = useMutation({
    mutationFn: async ({
      id,
      body,
    }: {
      id: string;
      body: { status?: 'active' | 'inactive'; is_default?: boolean };
    }) => api.PATCH('/warehouses/{id}', { params: { path: { id } }, body }),
    onSuccess: refresh,
  });
  const errors = fieldErrors(create.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate();
  };

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader
        title="Dépôts"
        description="Un dépôt n’est jamais supprimé : il se désactive. Le dépôt par défaut sert aux ventes."
      />
      <Card>
        <ErrorAlert error={update.error} />
        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
          {(warehouses.data ?? []).map((w) => (
            <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
              <div>
                <p className="font-medium">
                  {w.name} <span className="text-sm text-slate-500">({w.code})</span>{' '}
                  {w.is_default && <Badge tone="success">par défaut</Badge>}{' '}
                  {w.status === 'inactive' && <Badge>inactif</Badge>}
                </p>
                <p className="text-xs text-slate-500">{w.address ?? '—'}</p>
              </div>
              {canManage && (
                <div className="flex gap-1">
                  {!w.is_default && w.status === 'active' && (
                    <Button
                      variant="ghost"
                      onClick={() => update.mutate({ id: w.id, body: { is_default: true } })}
                    >
                      Définir par défaut
                    </Button>
                  )}
                  {!w.is_default && (
                    <Button
                      variant="ghost"
                      onClick={() =>
                        update.mutate({
                          id: w.id,
                          body: { status: w.status === 'active' ? 'inactive' : 'active' },
                        })
                      }
                    >
                      {w.status === 'active' ? 'Désactiver' : 'Réactiver'}
                    </Button>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      </Card>
      {canManage && (
        <Card title="Nouveau dépôt">
          <form onSubmit={submit} className="grid gap-3 sm:grid-cols-4 sm:items-end" noValidate>
            <TextField
              label="Code"
              error={errors.code}
              value={form.code}
              onChange={(e) => setForm({ ...form, code: e.target.value })}
            />
            <TextField
              label="Nom"
              error={errors.name}
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            <TextField
              label="Adresse"
              value={form.address}
              onChange={(e) => setForm({ ...form, address: e.target.value })}
            />
            <Button type="submit" loading={create.isPending}>
              Créer
            </Button>
          </form>
        </Card>
      )}
    </div>
  );
}
