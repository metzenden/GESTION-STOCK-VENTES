import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';
import { supplierTypeLabels, useCurrencies, useSuppliers } from '@/modules/purchasing/hooks';

function NewSupplier({ onDone }: { onDone: () => void }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [form, setForm] = useState({
    code: '',
    name: '',
    supplier_type: 'goods',
    currency: 'XOF',
    country_code: '',
    phone: '',
  });
  const create = useMutation({
    mutationFn: async () =>
      api.POST('/suppliers', {
        body: {
          code: form.code,
          name: form.name,
          supplier_type: form.supplier_type as 'goods',
          currency: form.currency,
          country_code: form.country_code === '' ? null : form.country_code.toUpperCase(),
          phone: form.phone === '' ? null : form.phone,
        },
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['suppliers'] });
      onDone();
    },
  });
  const errors = fieldErrors(create.error);
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) =>
    setForm({ ...form, [key]: e.target.value });

  return (
    <Card title="Nouveau fournisseur">
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label="Code" value={form.code} onChange={set('code')} error={errors.code} />
        <TextField label="Nom" value={form.name} onChange={set('name')} error={errors.name} />
        <SelectField label="Type" value={form.supplier_type} onChange={set('supplier_type')}>
          {Object.entries(supplierTypeLabels).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Devise de facturation"
          value={form.currency}
          onChange={set('currency')}
          error={errors.currency}
        >
          {currencies.map((c) => (
            <option key={c}>{c}</option>
          ))}
        </SelectField>
        <TextField
          label="Pays (code ISO, ex. CN)"
          maxLength={2}
          value={form.country_code}
          onChange={set('country_code')}
          error={errors.country_code}
        />
        <TextField label="Téléphone" value={form.phone} onChange={set('phone')} />
      </div>
      <ErrorAlert error={create.error} />
      <div className="mt-3 flex gap-2">
        <Button
          onClick={() => create.mutate()}
          loading={create.isPending}
          disabled={form.code === '' || form.name === ''}
        >
          Enregistrer
        </Button>
        <Button variant="ghost" onClick={onDone}>
          Annuler
        </Button>
      </div>
    </Card>
  );
}

export function SuppliersPage() {
  const canManage = useCan('suppliers.manage');
  const canFinance = useCan('supplier_payments.manage');
  const [creating, setCreating] = useState(false);
  const suppliers = useSuppliers();

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Fournisseurs"
        description="Fournisseurs de marchandises, transitaires, transporteurs et douane, chacun avec sa devise."
        actions={
          canManage && !creating && <Button onClick={() => setCreating(true)}>Nouveau fournisseur</Button>
        }
      />
      {creating && <NewSupplier onDone={() => setCreating(false)} />}
      <Card>
        {suppliers.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(suppliers.data ?? []).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <strong>{s.code}</strong> · {s.name}{' '}
                  <span className="text-slate-500">
                    · {supplierTypeLabels[s.supplier_type]} · {s.currency}
                  </span>
                </span>
                <span className="flex items-center gap-3">
                  {canFinance && (
                    <Link
                      to={`/achats/fournisseurs/${s.id}`}
                      className="text-brand-700 underline dark:text-brand-500"
                    >
                      Relevé
                    </Link>
                  )}
                  <StatusBadge status={s.status} />
                </span>
              </li>
            ))}
            {suppliers.data?.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucun fournisseur.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

export function SupplierStatementPage() {
  const { id = '' } = useParams();
  const statement = useQuery({
    queryKey: ['supplier-statement', id],
    queryFn: async () =>
      (await api.GET('/suppliers/{id}/statement', { params: { path: { id } } })).data?.data,
  });

  if (statement.isPending) return <Spinner />;
  if (statement.data === undefined) return <ErrorAlert error={statement.error} />;
  const st = statement.data;

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/achats/fournisseurs" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Fournisseurs
      </Link>
      <PageHeader
        title={`Relevé — ${st.supplier.name}`}
        description="Dette = engagements − règlements, par devise (jamais convertie)."
      />
      <div className="grid gap-3 sm:grid-cols-3">
        {st.balances.map((b) => (
          <Card key={b.currency}>
            <p className="text-sm text-slate-500">Solde dû ({b.currency})</p>
            <p className="text-2xl font-semibold">
              {formatMoney({ amount: b.balance, currency: b.currency })}
            </p>
            <p className="text-xs text-slate-500">
              engagé {formatMoney({ amount: b.committed, currency: b.currency })} · réglé{' '}
              {formatMoney({ amount: b.paid, currency: b.currency })}
            </p>
          </Card>
        ))}
        {st.balances.length === 0 && <p className="text-sm text-slate-500">Aucun engagement.</p>}
      </div>
      <Card title="Engagements">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {st.bills.map((b) => (
            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <strong>{b.number}</strong> · {b.bill_date} · {b.description ?? b.source_type}
              </span>
              <span className="flex items-center gap-2">
                {formatMoney({ amount: b.amount, currency: b.currency })}
                <StatusBadge status={b.status} />
              </span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
