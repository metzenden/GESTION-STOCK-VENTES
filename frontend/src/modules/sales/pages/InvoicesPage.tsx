import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { accountTypeFor, methodLabels, useFinancialAccounts } from '@/modules/sales/hooks';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

export function InvoicesPage({ receivables = false }: { receivables?: boolean }) {
  const [overdue, setOverdue] = useState(false);
  const invoices = useQuery({
    queryKey: ['invoices', receivables, overdue],
    queryFn: async () =>
      receivables
        ? (
            await api.GET('/receivables', {
              params: { query: { per_page: 100, ...(overdue ? { 'filter[overdue]': true } : {}) } },
            })
          ).data
        : (await api.GET('/invoices', { params: { query: { per_page: 50 } } })).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title={receivables ? 'Créances clients' : 'Factures'}
        description={
          receivables
            ? 'Factures avec un solde dû ; relance WhatsApp préremplie.'
            : 'Factures émises, immuables ; corrections par avoir.'
        }
      />
      {receivables && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} /> En retard
          seulement
        </label>
      )}
      <Card>
        {invoices.isPending ? (
          <Spinner />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {(invoices.data?.data ?? []).map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <Link
                    to={`/factures/${i.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {i.number}
                  </Link>{' '}
                  · {i.customer.name} · échéance {i.due_date}{' '}
                  {i.is_overdue && <strong className="text-red-700">· en retard</strong>}
                </span>
                <span className="flex items-center gap-3">
                  {formatMoney({ amount: receivables ? i.balance_due : i.total, currency: i.currency })}
                  <StatusBadge status={i.status === 'cancelled' ? 'cancelled' : i.payment_status} />
                </span>
              </li>
            ))}
            {invoices.data?.data.length === 0 && (
              <li className="py-2 text-sm text-slate-500">Aucune facture.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  );
}

export function InvoiceDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const canPay = useCan('customer_payments.create');
  const canReverse = useCan('customer_payments.reverse');
  const canPrint = useCan('invoices.print');
  const accounts = useFinancialAccounts();
  const payKey = useRef(newIdempotencyKey());
  const [method, setMethod] = useState('cash');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [whatsapp, setWhatsapp] = useState<string | null>(null);
  const invoice = useQuery({
    queryKey: ['invoice', id],
    queryFn: async () =>
      (await api.GET('/invoices/{invoice}', { params: { path: { invoice: id } } })).data?.data,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['invoice', id] });
    void queryClient.invalidateQueries({ queryKey: ['invoices'] });
  };
  const pay = useMutation({
    mutationFn: async (accountId: string) => {
      const r = await api.POST('/invoices/{invoice}/payments', {
        params: { path: { invoice: id }, header: { 'Idempotency-Key': payKey.current } },
        body: {
          method: method as 'cash',
          financial_account_id: accountId,
          amount: amount.replace(/\s/g, '').replace(',', '.'),
          external_reference: reference || null,
        },
      });
      payKey.current = newIdempotencyKey();
      return r;
    },
    onSuccess: () => {
      setAmount('');
      setReference('');
      refresh();
    },
  });
  const reverse = useMutation({
    mutationFn: async (paymentId: string) =>
      api.POST('/payments/{payment}/reverse', {
        params: { path: { payment: paymentId }, header: { 'Idempotency-Key': newIdempotencyKey() } },
        body: { reason: 'Correction' },
      }),
    onSuccess: refresh,
  });
  const share = useMutation({
    mutationFn: async () =>
      (await api.GET('/invoices/{invoice}/whatsapp-message', { params: { path: { invoice: id } } })).data
        ?.data,
    onSuccess: (m) => setWhatsapp(m?.url ?? m?.share_link ?? null),
  });

  if (invoice.isPending) return <Spinner />;
  if (invoice.data === undefined) return <ErrorAlert error={invoice.error} />;
  const inv = invoice.data;
  const m = (a: string) => formatMoney({ amount: a, currency: inv.currency });
  const effectiveMethod = inv.payment_method ?? method;
  const account = (accounts.data ?? []).find(
    (a) =>
      a.account_type === accountTypeFor[effectiveMethod] &&
      a.currency === inv.currency &&
      a.status === 'active',
  );

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <Link to="/factures" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Factures
      </Link>
      <PageHeader
        title={`Facture ${inv.number}`}
        description={`${inv.customer.name ?? ''} · émise le ${inv.issue_date} · échéance ${inv.due_date}`}
        actions={<StatusBadge status={inv.status === 'cancelled' ? 'cancelled' : inv.payment_status} />}
      />
      <Card title="Lignes">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(
            (inv.lines ?? []) as unknown as {
              id: string;
              description: string;
              qty_units: number;
              unit_label: string;
              line_total: string;
            }[]
          ).map((l) => (
            <li key={l.id} className="flex justify-between gap-2 py-2">
              <span>
                {l.description} · {l.qty_units} {l.unit_label}
              </span>
              <span>{m(l.line_total)}</span>
            </li>
          ))}
        </ul>
        <div className="mt-3 space-y-1 text-right text-sm">
          <p>Total {m(inv.total)}</p>
          <p>
            Réglé {m(inv.amount_paid)} · avoirs {m(inv.amount_credited)}
          </p>
          <p className="text-base font-semibold">Reste à payer {m(inv.balance_due)}</p>
        </div>
      </Card>
      {canPrint && (
        <div className="flex flex-wrap gap-2">
          <a
            className="rounded-md border px-3 py-2 text-sm"
            href={`/api/v1/invoices/${inv.id}/pdf`}
            target="_blank"
            rel="noreferrer"
          >
            PDF
          </a>
          <Button variant="secondary" onClick={() => share.mutate()}>
            Préparer l’envoi WhatsApp
          </Button>
          {whatsapp !== null && (
            <a
              className="rounded-md bg-green-700 px-3 py-2 text-sm text-white"
              href={whatsapp}
              target="_blank"
              rel="noreferrer"
            >
              Ouvrir WhatsApp
            </a>
          )}
        </div>
      )}
      {canPay && inv.status === 'issued' && inv.balance_due !== '0.0000' && (
        <Card title="Enregistrer un règlement">
          <div className="grid gap-3 sm:grid-cols-3">
            <SelectField
              label="Moyen"
              value={effectiveMethod}
              disabled={inv.payment_method !== null}
              onChange={(e) => setMethod(e.target.value)}
            >
              {Object.entries(methodLabels).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </SelectField>
            <TextField
              label="Montant"
              inputMode="decimal"
              placeholder={inv.balance_due}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            <TextField
              label="Référence (Wave, OM…)"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
            />
          </div>
          {inv.payment_method !== null && (
            <p className="mt-2 text-xs text-slate-500">
              Moyen fixé par le premier règlement (changement par contre-passation).
            </p>
          )}
          {account === undefined && (
            <p className="mt-2 text-sm text-amber-700">Aucun compte actif pour ce moyen.</p>
          )}
          <ErrorAlert error={pay.error} />
          <Button
            className="mt-3"
            disabled={account === undefined || amount === ''}
            loading={pay.isPending}
            onClick={() => account !== undefined && pay.mutate(account.id)}
          >
            Encaisser
          </Button>
        </Card>
      )}
      <Card title="Règlements">
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(
            (inv.payments ?? []) as unknown as {
              id: string;
              number: string;
              method: string;
              amount: string;
              status: string;
              reverses_payment_id: string | null;
            }[]
          ).map((p) => (
            <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                {p.number} · {methodLabels[p.method]} · {m(p.amount)}
              </span>
              <span className="flex items-center gap-2">
                <a
                  className="text-xs underline"
                  href={`/api/v1/payments/${p.id}/receipt.pdf`}
                  target="_blank"
                  rel="noreferrer"
                >
                  Reçu
                </a>
                <StatusBadge status={p.status} />
                {canReverse &&
                  p.status === 'posted' &&
                  p.reverses_payment_id === null &&
                  !p.amount.startsWith('-') && (
                    <Button variant="ghost" onClick={() => reverse.mutate(p.id)}>
                      Contre-passer
                    </Button>
                  )}
              </span>
            </li>
          ))}
        </ul>
        <ErrorAlert error={reverse.error} />
      </Card>
    </div>
  );
}
