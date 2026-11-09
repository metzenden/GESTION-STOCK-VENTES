import { useMutation } from '@tanstack/react-query';
import { Minus, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { api, newIdempotencyKey } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { formatMoney } from '@/lib/money';
import { variantLabel, VariantPicker, type VariantRef } from '@/modules/catalog/components/VariantPicker';
import { accountTypeFor, methodLabels, useFinancialAccounts } from '@/modules/sales/hooks';

/** Vente comptoir (SAL-008), pensée pour le téléphone : scanner, ajuster, encaisser — une seule transaction. */
export function CounterSalePage() {
  const accounts = useFinancialAccounts();
  const [lines, setLines] = useState<{ variant: VariantRef; qty: number }[]>([]);
  const [method, setMethod] = useState('cash');
  const [done, setDone] = useState<{
    number: string;
    total: string;
    currency: string;
    invoiceId: string | null;
  } | null>(null);
  const key = useRef(newIdempotencyKey());
  const account = (accounts.data ?? []).find(
    (a) => a.account_type === accountTypeFor[method] && a.status === 'active',
  );

  const sell = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/counter-sales', {
          params: { header: { 'Idempotency-Key': key.current } },
          body: {
            lines: lines.map((l) => ({ variant_id: l.variant.id, qty_units: l.qty })),
            payment:
              account !== undefined ? { method: method as 'cash', financial_account_id: account.id } : null,
          },
        })
      ).data?.data,
    onSuccess: (order) => {
      key.current = newIdempotencyKey();
      setLines([]);
      if (order !== undefined)
        setDone({
          number: order.number,
          total: order.total,
          currency: order.currency,
          invoiceId: order.invoice_id,
        });
    },
  });

  const add = (variant: VariantRef) => {
    setDone(null);
    setLines((current) =>
      current.some((l) => l.variant.id === variant.id)
        ? current.map((l) => (l.variant.id === variant.id ? { ...l, qty: l.qty + 1 } : l))
        : [...current, { variant, qty: 1 }],
    );
  };
  const setQty = (index: number, qty: number) =>
    setLines(lines.map((l, i) => (i === index ? { ...l, qty: Math.max(1, qty) } : l)));

  return (
    <div className="mx-auto max-w-lg space-y-4">
      <PageHeader
        title="Vente comptoir"
        description="Commande, facture, sortie de stock et encaissement en une seule opération."
      />
      <Card>
        <VariantPicker label="Scanner ou rechercher un article" onSelect={add} />
        <ul className="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
          {lines.map((l, i) => (
            <li key={l.variant.id} className="flex items-center gap-2 py-2 text-sm">
              <span className="flex-1">{variantLabel(l.variant)}</span>
              <button
                type="button"
                aria-label="Moins"
                className="rounded border p-2"
                onClick={() => setQty(i, l.qty - 1)}
              >
                <Minus className="size-4" />
              </button>
              <span className="w-8 text-center text-base font-semibold">{l.qty}</span>
              <button
                type="button"
                aria-label="Plus"
                className="rounded border p-2"
                onClick={() => setQty(i, l.qty + 1)}
              >
                <Plus className="size-4" />
              </button>
              <button
                type="button"
                aria-label="Retirer"
                className="p-2 text-red-700"
                onClick={() => setLines(lines.filter((_, j) => j !== i))}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
          {lines.length === 0 && <li className="py-3 text-sm text-slate-500">Aucun article.</li>}
        </ul>
      </Card>
      <Card title="Paiement">
        <div className="grid grid-cols-2 gap-2">
          {Object.entries(methodLabels).map(([value, label]) => (
            <button
              key={value}
              type="button"
              aria-pressed={method === value}
              onClick={() => setMethod(value)}
              className={`rounded-md border px-3 py-3 text-sm font-medium ${method === value ? 'border-brand-600 bg-brand-50 text-brand-700 dark:bg-slate-800' : 'border-slate-300 dark:border-slate-700'}`}
            >
              {label}
            </button>
          ))}
        </div>
        {account === undefined && (
          <p className="mt-2 text-sm text-amber-700">
            Aucun compte actif pour ce moyen : la vente sera enregistrée à crédit.
          </p>
        )}
      </Card>
      <ErrorAlert error={sell.error} />
      <Button
        className="w-full py-3 text-base"
        onClick={() => sell.mutate()}
        disabled={lines.length === 0}
        loading={sell.isPending}
      >
        Encaisser et remettre
      </Button>
      {done !== null && (
        <Card>
          <p className="text-sm">
            Vente <strong>{done.number}</strong> enregistrée :{' '}
            {formatMoney({ amount: done.total, currency: done.currency })}.
          </p>
          {done.invoiceId !== null && (
            <Link
              to={`/factures/${done.invoiceId}`}
              className="text-sm text-brand-700 underline dark:text-brand-500"
            >
              Voir la facture
            </Link>
          )}
        </Card>
      )}
    </div>
  );
}
