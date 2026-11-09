import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { api, fieldErrors, newIdempotencyKey } from '@/api/client';
import type { components } from '@/api/schema';
import { ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import { variantLabel } from '@/modules/catalog/components/VariantPicker';
import { decimalInput, useCurrencies, useSuppliers } from '@/modules/purchasing/hooks';
import { StatusBadge } from '@/modules/stock/components/StatusBadge';

type Shipment = components['schemas']['Shipment'];

/** Frais d'approche et révisions de coût de revient d'un arrivage (informations sensibles). */
export function ShipmentCosts({ shipment }: { shipment: Shipment }) {
  const queryClient = useQueryClient();
  const canManage = useCan('costing.manage');
  const currencies = useCurrencies();
  const suppliers = useSuppliers();
  const computeKey = useRef(newIdempotencyKey());
  const [form, setForm] = useState({
    category_id: '',
    supplier_id: '',
    currency: 'XOF',
    amount: '',
    exchange_rate: '',
    allocation_method: 'cbm',
    target: '',
    description: '',
    is_estimate: false,
  });
  const open = !['closed', 'cancelled'].includes(shipment.status);

  const categories = useQuery({
    queryKey: ['landed-cost-categories'],
    queryFn: async () => (await api.GET('/landed-cost-categories')).data?.data ?? [],
  });
  const costs = useQuery({
    queryKey: ['shipment', shipment.id, 'costs'],
    queryFn: async () =>
      (await api.GET('/shipments/{shipment}/costs', { params: { path: { shipment: shipment.id } } })).data
        ?.data ?? [],
  });
  const calculations = useQuery({
    queryKey: ['shipment', shipment.id, 'landed-costs'],
    queryFn: async () =>
      (await api.GET('/shipments/{shipment}/landed-costs', { params: { path: { shipment: shipment.id } } }))
        .data?.data ?? [],
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['shipment', shipment.id, 'costs'] });
    void queryClient.invalidateQueries({ queryKey: ['shipment', shipment.id, 'landed-costs'] });
  };
  const create = useMutation({
    mutationFn: async () =>
      api.POST('/shipments/{shipment}/costs', {
        params: { path: { shipment: shipment.id } },
        body: {
          category_id: form.category_id,
          supplier_id: form.supplier_id || null,
          currency: form.currency,
          amount: decimalInput(form.amount),
          ...(form.exchange_rate !== '' ? { exchange_rate: decimalInput(form.exchange_rate) } : {}),
          allocation_method: form.allocation_method as 'cbm' | 'direct',
          target_shipment_line_id: form.allocation_method === 'direct' ? form.target : null,
          description: form.description || null,
          is_estimate: form.is_estimate,
        },
      }),
    onSuccess: () => {
      setForm({ ...form, amount: '', description: '', exchange_rate: '' });
      refresh();
    },
  });
  const costAction = useMutation({
    mutationFn: async ({ id, action }: { id: string; action: 'confirm' | 'cancel' }) =>
      action === 'confirm'
        ? api.POST('/shipments/{shipment}/costs/{cost}/confirm', {
            params: {
              path: { shipment: shipment.id, cost: id },
              header: { 'Idempotency-Key': newIdempotencyKey() },
            },
          })
        : api.POST('/shipments/{shipment}/costs/{cost}/cancel', {
            params: { path: { shipment: shipment.id, cost: id } },
          }),
    onSuccess: refresh,
  });
  const compute = useMutation({
    mutationFn: async () => {
      const result = await api.POST('/shipments/{shipment}/landed-costs', {
        params: { path: { shipment: shipment.id }, header: { 'Idempotency-Key': computeKey.current } },
        body: {},
      });
      computeKey.current = newIdempotencyKey();
      return result;
    },
    onSuccess: refresh,
  });
  const errors = fieldErrors(create.error);
  const categoryLabel = new Map((categories.data ?? []).map((c) => [c.id, c.label]));

  return (
    <Card title="Frais d’approche et coût de revient">
      <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
        {(costs.data ?? []).map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <span>
              {categoryLabel.get(c.category_id)} {c.description !== null && `· ${c.description}`}{' '}
              <span className="text-slate-500">
                · {c.allocation_method === 'cbm' ? 'réparti au CBM' : 'direct'}
                {c.supplier !== null && ` · ${c.supplier.name}`}
                {c.is_estimate && ' · estimé'}
              </span>
            </span>
            <span className="flex items-center gap-2">
              {formatMoney({ amount: c.amount, currency: c.currency })}
              {c.currency !== 'XOF' && (
                <span className="text-xs text-slate-500">
                  ({formatMoney({ amount: c.amount_reference, currency: 'XOF' })})
                </span>
              )}
              <StatusBadge status={c.status} />
              {canManage && open && c.status === 'draft' && (
                <Button
                  variant="secondary"
                  onClick={() => costAction.mutate({ id: c.id, action: 'confirm' })}
                >
                  Confirmer
                </Button>
              )}
              {canManage && open && c.status !== 'cancelled' && (
                <Button variant="ghost" onClick={() => costAction.mutate({ id: c.id, action: 'cancel' })}>
                  Annuler
                </Button>
              )}
            </span>
          </li>
        ))}
        {(costs.data ?? []).length === 0 && <li className="py-2 text-slate-500">Aucun frais saisi.</li>}
      </ul>
      <ErrorAlert error={costAction.error} />

      {canManage && open && (
        <div className="mt-4 grid gap-3 border-t border-slate-200 pt-4 dark:border-slate-800 sm:grid-cols-3">
          <SelectField
            label="Catégorie"
            value={form.category_id}
            onChange={(e) => setForm({ ...form, category_id: e.target.value })}
            error={errors.category_id}
          >
            <option value="">Choisir…</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.label}
              </option>
            ))}
          </SelectField>
          <SelectField
            label="Prestataire (engagement)"
            value={form.supplier_id}
            onChange={(e) => setForm({ ...form, supplier_id: e.target.value })}
          >
            <option value="">Aucun</option>
            {(suppliers.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </SelectField>
          <TextField
            label="Libellé"
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
          <SelectField
            label="Devise"
            value={form.currency}
            onChange={(e) => setForm({ ...form, currency: e.target.value })}
          >
            {currencies.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </SelectField>
          <TextField
            label="Montant"
            inputMode="decimal"
            value={form.amount}
            onChange={(e) => setForm({ ...form, amount: e.target.value })}
            error={errors.amount}
          />
          {form.currency !== 'XOF' && (
            <TextField
              label={`Taux ${form.currency} → XOF`}
              hint="Vide : dernier taux enregistré"
              inputMode="decimal"
              value={form.exchange_rate}
              onChange={(e) => setForm({ ...form, exchange_rate: e.target.value })}
              error={errors.exchange_rate}
            />
          )}
          <SelectField
            label="Répartition"
            value={form.allocation_method}
            onChange={(e) => setForm({ ...form, allocation_method: e.target.value })}
          >
            <option value="cbm">Au volume (CBM)</option>
            <option value="direct">Directe sur un article</option>
          </SelectField>
          {form.allocation_method === 'direct' && (
            <SelectField
              label="Article visé"
              value={form.target}
              onChange={(e) => setForm({ ...form, target: e.target.value })}
              error={errors.target_shipment_line_id}
            >
              <option value="">Choisir…</option>
              {(shipment.lines ?? []).map((l) => (
                <option key={l.id} value={l.id}>
                  {l.position}. {variantLabel(l.variant)}
                </option>
              ))}
            </SelectField>
          )}
          <label className="flex items-center gap-2 self-end text-sm">
            <input
              type="checkbox"
              checked={form.is_estimate}
              onChange={(e) => setForm({ ...form, is_estimate: e.target.checked })}
            />
            Montant estimé
          </label>
          <div className="self-end">
            <Button
              onClick={() => create.mutate()}
              loading={create.isPending}
              disabled={form.category_id === '' || form.amount === ''}
            >
              Ajouter le frais
            </Button>
          </div>
          <div className="sm:col-span-3">
            <ErrorAlert error={create.error} />
          </div>
        </div>
      )}

      <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-800">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">Calculs</h3>
          {canManage && open && !(calculations.data ?? []).some((c) => c.status === 'final') && (
            <Button onClick={() => compute.mutate()} loading={compute.isPending}>
              Calculer le coût de revient
            </Button>
          )}
        </div>
        <ErrorAlert error={compute.error} />
        <ul className="mt-2 text-sm">
          {(calculations.data ?? []).map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-1">
              <Link to={`/couts/${c.id}`} className="text-brand-700 underline dark:text-brand-500">
                Révision {c.revision} ·{' '}
                {formatMoney({ amount: c.total_cost, currency: c.reference_currency })}
              </Link>
              <span className="flex items-center gap-2">
                {c.blocking_issues.length > 0 && (
                  <span className="text-xs text-amber-700">{c.blocking_issues.length} anomalie(s)</span>
                )}
                <StatusBadge status={c.status} />
              </span>
            </li>
          ))}
        </ul>
      </div>
    </Card>
  );
}
