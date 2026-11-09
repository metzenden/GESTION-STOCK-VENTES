import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { useFinancialAccounts } from '@/modules/sales/hooks';

/** Trésorerie (L5-06) : soldes par compte, transferts, dépenses et rubrique « En attente ». */
export function TreasuryPage() {
  const queryClient = useQueryClient();
  const canManage = useCan('financial_accounts.manage');
  const canExpense = useCan('expenses.manage');
  const accounts = useFinancialAccounts();
  const [transfer, setTransfer] = useState({ from: '', to: '', amount: '', rate: '' });
  const [expense, setExpense] = useState({
    category: '',
    account: '',
    label: '',
    amount: '',
    date: new Date().toISOString().slice(0, 10),
  });
  const categories = useQuery({
    queryKey: ['expense-categories'],
    enabled: canExpense,
    queryFn: async () => (await api.GET('/expense-categories')).data?.data ?? [],
  });
  const expenses = useQuery({
    queryKey: ['expenses'],
    enabled: canExpense,
    queryFn: async () =>
      (await api.GET('/expenses', { params: { query: { per_page: 20 } } })).data?.data ?? [],
  });
  const pending = useQuery({
    queryKey: ['financial-pending'],
    queryFn: async () => (await api.GET('/financial/pending')).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['financial-accounts'] });
    void queryClient.invalidateQueries({ queryKey: ['expenses'] });
  };
  const doTransfer = useMutation({
    mutationFn: async () =>
      api.POST('/account-transfers', {
        params: { header: { 'Idempotency-Key': newIdempotencyKey() } },
        body: {
          from_account_id: transfer.from,
          to_account_id: transfer.to,
          amount: transfer.amount.replace(',', '.'),
          exchange_rate: transfer.rate === '' ? null : transfer.rate.replace(',', '.'),
        },
      }),
    onSuccess: () => {
      setTransfer({ from: '', to: '', amount: '', rate: '' });
      refresh();
    },
  });
  const doExpense = useMutation({
    mutationFn: async () => {
      const body = new FormData();
      body.append('category_id', expense.category);
      body.append('financial_account_id', expense.account);
      body.append('expense_date', expense.date);
      body.append('label', expense.label);
      body.append('amount', expense.amount.replace(',', '.'));
      return api.POST('/expenses', {
        params: { header: { 'Idempotency-Key': newIdempotencyKey() } },
        body: body as unknown as {
          category_id: string;
          financial_account_id: string;
          expense_date: string;
          label: string;
          amount: string;
        },
        bodySerializer: (b) => b as unknown as FormData,
      });
    },
    onSuccess: () => {
      setExpense({ ...expense, label: '', amount: '' });
      refresh();
    },
  });
  const pendingCorrections = ((pending.data as { corrections?: unknown[] } | undefined)?.corrections ??
    []) as { id: string; amount: string; reason: string }[];

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Trésorerie"
        description="Solde = ouverture + entrées − sorties ; les opérations en attente d'approbation n'écrivent rien."
      />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {(accounts.data ?? []).map((a) => (
          <Card key={a.id}>
            <p className="text-sm text-slate-500">{a.name}</p>
            <p className="text-xl font-semibold">
              {a.balance !== undefined ? formatMoney({ amount: a.balance, currency: a.currency }) : '—'}
            </p>
          </Card>
        ))}
      </div>
      {pendingCorrections.length > 0 && (
        <Card title="En attente d'approbation">
          <ul className="text-sm">
            {pendingCorrections.map((c) => (
              <li key={c.id}>
                {c.reason} · {c.amount}
              </li>
            ))}
          </ul>
        </Card>
      )}
      {canManage && (
        <Card title="Transfert entre comptes">
          <div className="grid gap-3 sm:grid-cols-4">
            {(['from', 'to'] as const).map((k) => (
              <SelectField
                key={k}
                label={k === 'from' ? 'Depuis' : 'Vers'}
                value={transfer[k]}
                onChange={(e) => setTransfer({ ...transfer, [k]: e.target.value })}
              >
                <option value="">Choisir…</option>
                {(accounts.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name} ({a.currency})
                  </option>
                ))}
              </SelectField>
            ))}
            <TextField
              label="Montant"
              inputMode="decimal"
              value={transfer.amount}
              onChange={(e) => setTransfer({ ...transfer, amount: e.target.value })}
            />
            <TextField
              label="Taux (si devises différentes)"
              inputMode="decimal"
              value={transfer.rate}
              onChange={(e) => setTransfer({ ...transfer, rate: e.target.value })}
            />
          </div>
          <ErrorAlert error={doTransfer.error} />
          <Button
            className="mt-3"
            disabled={transfer.from === '' || transfer.to === '' || transfer.amount === ''}
            onClick={() => doTransfer.mutate()}
          >
            Transférer
          </Button>
        </Card>
      )}
      {canExpense && (
        <Card title="Dépenses">
          <div className="grid gap-3 sm:grid-cols-5">
            <SelectField
              label="Catégorie"
              value={expense.category}
              onChange={(e) => setExpense({ ...expense, category: e.target.value })}
            >
              <option value="">Choisir…</option>
              {(categories.data ?? []).map((c) => (
                <option key={String(c.id)} value={String(c.id)}>
                  {String(c.name)}
                </option>
              ))}
            </SelectField>
            <SelectField
              label="Compte"
              value={expense.account}
              onChange={(e) => setExpense({ ...expense, account: e.target.value })}
            >
              <option value="">Choisir…</option>
              {(accounts.data ?? []).map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </SelectField>
            <TextField
              label="Date"
              type="date"
              value={expense.date}
              onChange={(e) => setExpense({ ...expense, date: e.target.value })}
            />
            <TextField
              label="Libellé"
              value={expense.label}
              onChange={(e) => setExpense({ ...expense, label: e.target.value })}
            />
            <TextField
              label="Montant"
              inputMode="decimal"
              value={expense.amount}
              onChange={(e) => setExpense({ ...expense, amount: e.target.value })}
            />
          </div>
          <ErrorAlert error={doExpense.error} />
          <Button
            className="mt-3"
            disabled={
              expense.category === '' ||
              expense.account === '' ||
              expense.label === '' ||
              expense.amount === ''
            }
            onClick={() => doExpense.mutate()}
          >
            Enregistrer la dépense
          </Button>
          <ul className="mt-4 divide-y divide-slate-200 text-sm dark:divide-slate-800">
            {(expenses.data ?? []).map((e) => (
              <li key={String(e.id)} className="flex justify-between py-1.5">
                <span>
                  {String(e.number)} · {String(e.expense_date)} · {String(e.label)}
                </span>
                <span>
                  {formatMoney({ amount: String(e.amount), currency: String(e.currency) })}{' '}
                  {e.status === 'reversed' && '(contre-passée)'}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}
