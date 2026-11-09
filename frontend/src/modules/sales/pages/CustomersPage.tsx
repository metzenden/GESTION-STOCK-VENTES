import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';
import { useCustomers } from '@/modules/sales/hooks';

export function CustomersPage() {
  const queryClient = useQueryClient();
  const canManage = useCan('customers.manage');
  const [term, setTerm] = useState('');
  const [form, setForm] = useState({ name: '', phone: '', payment_terms_days: '' });
  const customers = useCustomers(term);
  const create = useMutation({
    mutationFn: async () =>
      api.POST('/customers', {
        body: {
          name: form.name,
          phone: form.phone || null,
          payment_terms_days: form.payment_terms_days === '' ? null : Number(form.payment_terms_days),
        },
      }),
    onSuccess: () => {
      setForm({ name: '', phone: '', payment_terms_days: '' });
      void queryClient.invalidateQueries({ queryKey: ['customers'] });
    },
  });
  const errors = fieldErrors(create.error);

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader title="Clients" description="Recherche par nom, code ou téléphone." />
      {canManage && (
        <Card title="Nouveau client">
          <div className="grid gap-3 sm:grid-cols-3">
            <TextField
              label="Nom"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              error={errors.name}
            />
            <TextField
              label="Téléphone"
              inputMode="tel"
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
            />
            <TextField
              label="Délai de paiement (jours)"
              inputMode="numeric"
              value={form.payment_terms_days}
              onChange={(e) => setForm({ ...form, payment_terms_days: e.target.value })}
            />
          </div>
          <ErrorAlert error={create.error} />
          <Button
            className="mt-3"
            onClick={() => create.mutate()}
            disabled={form.name === ''}
            loading={create.isPending}
          >
            Enregistrer
          </Button>
        </Card>
      )}
      <Card>
        <TextField label="Rechercher" value={term} onChange={(e) => setTerm(e.target.value)} />
        {customers.isPending ? (
          <Spinner />
        ) : (
          <ul className="mt-3 divide-y divide-slate-200 text-sm dark:divide-slate-800">
            {(customers.data ?? []).map((c) => (
              <li key={c.id} className="flex flex-wrap justify-between gap-2 py-2">
                <span>
                  <strong>{c.code}</strong> · {c.name}
                </span>
                <span className="text-slate-500">{c.phone ?? ''}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
