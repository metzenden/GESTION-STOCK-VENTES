import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { api, fieldErrors } from '@/api/client';
import type { components } from '@/api/schema';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';

type CompanySettings = components['schemas']['CompanySettings'];
type SequenceFormat = components['schemas']['DocumentSequenceFormat'];

const docTypeLabels: Record<string, string> = {
  invoice: 'Facture',
  credit_note: 'Avoir',
  receipt: 'Reçu',
  quotation: 'Devis',
  sales_order: 'Commande client',
  delivery: 'Bon de livraison',
  return: 'Retour',
  purchase_order: "Commande d'achat",
  supplier_bill: 'Engagement fournisseur',
  supplier_payment: 'Paiement fournisseur',
  shipment: 'Arrivage',
  goods_receipt: 'Bon de réception',
  stock_lot: 'Lot de stock',
  adjustment: 'Ajustement',
  inventory: 'Inventaire',
  transfer: 'Transfert',
  expense: 'Dépense',
  account_transfer: 'Virement interne',
  approval_request: "Demande d'approbation",
};

function nullable(value: string): string | null {
  return value.trim() === '' ? null : value;
}

function CompanyForm({ initial }: { initial: CompanySettings }) {
  const queryClient = useQueryClient();
  const canManage = useCan('settings.manage');
  const [identity, setIdentity] = useState(initial.identity);
  const [settings, setSettings] = useState(initial.settings);

  const save = useMutation({
    mutationFn: async () =>
      api.PATCH('/settings/company', {
        body: {
          identity: {
            trade_name: identity.trade_name ?? '',
            legal_name: nullable(identity.legal_name ?? ''),
            ninea: nullable(identity.ninea ?? ''),
            rccm: nullable(identity.rccm ?? ''),
            address: nullable(identity.address ?? ''),
            city: nullable(identity.city ?? ''),
            phone: nullable(identity.phone ?? ''),
            email: nullable(identity.email ?? ''),
          },
          settings: {
            default_payment_terms_days: settings.default_payment_terms_days,
            invoice_legal_mentions: nullable(settings.invoice_legal_mentions ?? ''),
            invoice_footer: nullable(settings.invoice_footer ?? ''),
            payment_instructions: nullable(settings.payment_instructions ?? ''),
            start_date: nullable(settings.start_date ?? ''),
          },
        },
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['settings'] });
      void queryClient.invalidateQueries({ queryKey: ['auth'] });
    },
  });

  const errors = fieldErrors(save.error);
  const text = (
    key: 'trade_name' | 'legal_name' | 'ninea' | 'rccm' | 'address' | 'city' | 'phone' | 'email',
  ) => ({
    value: identity[key] ?? '',
    error: errors[`identity/${key}`],
    disabled: !canManage,
    onChange: (e: { target: { value: string } }) => setIdentity({ ...identity, [key]: e.target.value }),
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <Card title="Identité">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Code" value={identity.code} disabled hint="Non modifiable" />
          <TextField label="Nom commercial" {...text('trade_name')} />
          <TextField label="Raison sociale" {...text('legal_name')} />
          <TextField label="NINEA" {...text('ninea')} />
          <TextField label="RCCM" {...text('rccm')} />
          <TextField label="Téléphone" {...text('phone')} />
          <TextField label="E-mail" type="email" {...text('email')} />
          <TextField label="Ville" {...text('city')} />
          <div className="sm:col-span-2">
            <TextField label="Adresse" {...text('address')} />
          </div>
        </div>
      </Card>
      <Card title="Facturation et règles">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label="Délai de paiement par défaut (jours)"
            type="number"
            min={0}
            max={365}
            disabled={!canManage}
            error={errors['settings/default_payment_terms_days']}
            value={String(settings.default_payment_terms_days)}
            onChange={(e) =>
              setSettings({
                ...settings,
                default_payment_terms_days: Number.parseInt(e.target.value || '0', 10),
              })
            }
          />
          <TextField
            label="Date de démarrage"
            type="date"
            disabled={!canManage}
            error={errors['settings/start_date']}
            value={settings.start_date ?? ''}
            onChange={(e) => setSettings({ ...settings, start_date: e.target.value })}
          />
          <SelectField label="Arrondi" value={settings.rounding_mode} disabled>
            <option value="half_up">Demi supérieur (à la précision de la devise)</option>
          </SelectField>
          <div className="sm:col-span-2">
            <TextAreaField
              label="Mentions légales des factures"
              hint="À faire valider par un expert-comptable (point réglementaire VR-01) : l'application n'impose aucune mention."
              disabled={!canManage}
              value={settings.invoice_legal_mentions ?? ''}
              onChange={(e) => setSettings({ ...settings, invoice_legal_mentions: e.target.value })}
            />
          </div>
          <TextAreaField
            label="Pied de facture"
            disabled={!canManage}
            value={settings.invoice_footer ?? ''}
            onChange={(e) => setSettings({ ...settings, invoice_footer: e.target.value })}
          />
          <TextAreaField
            label="Instructions de paiement"
            disabled={!canManage}
            value={settings.payment_instructions ?? ''}
            onChange={(e) => setSettings({ ...settings, payment_instructions: e.target.value })}
          />
        </div>
      </Card>
      {canManage && (
        <div className="space-y-2">
          {Object.keys(errors).length === 0 && <ErrorAlert error={save.error} />}
          {save.isSuccess && <Alert tone="success">Paramètres enregistrés.</Alert>}
          <Button type="submit" loading={save.isPending}>
            Enregistrer les paramètres
          </Button>
        </div>
      )}
    </form>
  );
}

function SequencesCard() {
  const queryClient = useQueryClient();
  const canManage = useCan('settings.manage');
  const formats = useQuery({
    queryKey: ['settings', 'sequences'],
    queryFn: async () => (await api.GET('/settings/document-sequences')).data?.data ?? [],
  });
  const [draft, setDraft] = useState<SequenceFormat[]>([]);

  useEffect(() => {
    if (formats.data !== undefined) setDraft(formats.data);
  }, [formats.data]);

  const save = useMutation({
    mutationFn: async () => {
      const changed = draft.filter((f) => {
        const original = formats.data?.find((o) => o.doc_type === f.doc_type);
        return original === undefined || original.prefix !== f.prefix || original.padding !== f.padding;
      });
      if (changed.length > 0) await api.PATCH('/settings/document-sequences', { body: { formats: changed } });
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['settings', 'sequences'] }),
  });

  const update = (docType: string, patch: Partial<SequenceFormat>) =>
    setDraft(draft.map((f) => (f.doc_type === docType ? { ...f, ...patch } : f)));
  const example = (f: SequenceFormat) =>
    `${f.prefix}-${new Date().getFullYear()}-${'1'.padStart(f.padding, '0')}`;

  return (
    <Card title="Numérotation des documents">
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-400">
        Le préfixe et la largeur s'appliquent aux prochains numéros. Les compteurs ne sont jamais modifiables
        : aucun numéro ne peut être réutilisé.
      </p>
      {formats.isPending ? (
        <Spinner />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[32rem] text-sm">
            <thead>
              <tr className="text-left text-slate-500">
                <th className="py-2 font-medium">Document</th>
                <th className="py-2 font-medium">Préfixe</th>
                <th className="py-2 font-medium">Chiffres</th>
                <th className="py-2 font-medium">Exemple</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
              {draft.map((f) => (
                <tr key={f.doc_type}>
                  <td className="py-1.5">{docTypeLabels[f.doc_type] ?? f.doc_type}</td>
                  <td className="py-1.5">
                    <input
                      aria-label={`Préfixe ${docTypeLabels[f.doc_type] ?? f.doc_type}`}
                      className="w-24 rounded border border-slate-300 bg-white px-2 py-1 uppercase dark:border-slate-700 dark:bg-slate-900"
                      value={f.prefix}
                      maxLength={12}
                      disabled={!canManage}
                      onChange={(e) => update(f.doc_type, { prefix: e.target.value.toUpperCase() })}
                    />
                  </td>
                  <td className="py-1.5">
                    <input
                      aria-label={`Chiffres ${docTypeLabels[f.doc_type] ?? f.doc_type}`}
                      type="number"
                      min={1}
                      max={10}
                      className="w-16 rounded border border-slate-300 bg-white px-2 py-1 dark:border-slate-700 dark:bg-slate-900"
                      value={f.padding}
                      disabled={!canManage}
                      onChange={(e) =>
                        update(f.doc_type, { padding: Number.parseInt(e.target.value || '1', 10) })
                      }
                    />
                  </td>
                  <td className="py-1.5 font-mono text-xs">{example(f)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {canManage && (
        <div className="mt-3 space-y-2">
          <ErrorAlert error={save.error} />
          {save.isSuccess && <Alert tone="success">Numérotation enregistrée.</Alert>}
          <Button onClick={() => save.mutate()} loading={save.isPending}>
            Enregistrer la numérotation
          </Button>
        </div>
      )}
    </Card>
  );
}

function ExchangeRatesCard() {
  const queryClient = useQueryClient();
  const canManage = useCan('settings.manage');
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({
    from_currency: 'EUR',
    to_currency: 'XOF',
    rate: '',
    valid_on: today,
    source: '',
  });
  const rates = useQuery({
    queryKey: ['settings', 'rates'],
    queryFn: async () => (await api.GET('/exchange-rates')).data?.data ?? [],
  });
  const add = useMutation({
    mutationFn: async () => api.POST('/exchange-rates', { body: { ...form, source: nullable(form.source) } }),
    onSuccess: () => {
      setForm({ ...form, rate: '', source: '' });
      void queryClient.invalidateQueries({ queryKey: ['settings', 'rates'] });
    },
  });
  const errors = fieldErrors(add.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    add.mutate();
  };
  const currencies = ['XOF', 'EUR', 'USD', 'CNY'];

  return (
    <Card title="Taux de change">
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-400">
        1 unité de la devise source = taux × devise cible, à la date indiquée. Un taux saisi n'est pas modifié
        : saisissez-en un nouveau.
      </p>
      {canManage && (
        <form
          onSubmit={submit}
          className="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6 lg:items-end"
          noValidate
        >
          <SelectField
            label="Source"
            value={form.from_currency}
            onChange={(e) => setForm({ ...form, from_currency: e.target.value })}
          >
            {currencies.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </SelectField>
          <SelectField
            label="Cible"
            error={errors.to_currency}
            value={form.to_currency}
            onChange={(e) => setForm({ ...form, to_currency: e.target.value })}
          >
            {currencies.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </SelectField>
          <TextField
            label="Taux"
            inputMode="decimal"
            error={errors.rate}
            value={form.rate}
            onChange={(e) => setForm({ ...form, rate: e.target.value.replace(',', '.') })}
          />
          <TextField
            label="Date"
            type="date"
            error={errors.valid_on}
            value={form.valid_on}
            onChange={(e) => setForm({ ...form, valid_on: e.target.value })}
          />
          <TextField
            label="Origine"
            placeholder="Banque, saisie…"
            value={form.source}
            onChange={(e) => setForm({ ...form, source: e.target.value })}
          />
          <Button type="submit" loading={add.isPending}>
            Ajouter
          </Button>
        </form>
      )}
      {rates.data?.length === 0 ? (
        <p className="text-sm text-slate-500">Aucun taux saisi.</p>
      ) : (
        <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
          {(rates.data ?? []).map((r) => (
            <li key={r.id} className="flex justify-between py-2">
              <span>
                1 {r.from_currency} = <span className="font-mono">{r.rate}</span> {r.to_currency}
              </span>
              <span className="text-slate-500">
                {r.valid_on} {r.source !== null && `· ${r.source}`}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function TaxRatesCard() {
  const queryClient = useQueryClient();
  const canManage = useCan('settings.manage');
  const [form, setForm] = useState({ name: '', rate: '' });
  const taxes = useQuery({
    queryKey: ['settings', 'taxes'],
    queryFn: async () => (await api.GET('/tax-rates')).data?.data ?? [],
  });
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['settings', 'taxes'] });
  const add = useMutation({
    mutationFn: async () => api.POST('/tax-rates', { body: form }),
    onSuccess: () => {
      setForm({ name: '', rate: '' });
      refresh();
    },
  });
  const toggle = useMutation({
    mutationFn: async ({ id, active }: { id: string; active: boolean }) =>
      api.PATCH('/tax-rates/{id}', { params: { path: { id } }, body: { is_active: active } }),
    onSuccess: refresh,
  });
  const errors = fieldErrors(add.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    add.mutate();
  };

  return (
    <Card title="Taxes">
      <Alert tone="info">
        Aucune taxe n'est créée par défaut. Les taux et leurs règles d'application doivent être validés par
        votre expert-comptable (point VR-01).
      </Alert>
      {canManage && (
        <form
          onSubmit={submit}
          className="my-4 grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
          noValidate
        >
          <TextField
            label="Nom"
            error={errors.name}
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
          />
          <TextField
            label="Taux (%)"
            inputMode="decimal"
            error={errors.rate}
            value={form.rate}
            onChange={(e) => setForm({ ...form, rate: e.target.value.replace(',', '.') })}
          />
          <Button type="submit" loading={add.isPending}>
            Ajouter
          </Button>
        </form>
      )}
      <ul className="divide-y divide-slate-200 text-sm dark:divide-slate-800">
        {(taxes.data ?? []).map((t) => (
          <li key={t.id} className="flex items-center justify-between gap-2 py-2">
            <span>
              {t.name} — {t.rate} % {!t.is_active && <Badge>Inactive</Badge>}
            </span>
            {canManage && (
              <Button variant="ghost" onClick={() => toggle.mutate({ id: t.id, active: !t.is_active })}>
                {t.is_active ? 'Désactiver' : 'Activer'}
              </Button>
            )}
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function SettingsPage() {
  const settings = useQuery({
    queryKey: ['settings', 'company'],
    queryFn: async () => (await api.GET('/settings/company')).data?.data,
  });

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader
        title="Paramètres"
        description="Paramètres de l'entreprise, modifiables sans intervention technique."
      />
      {settings.isPending ? (
        <Spinner />
      ) : settings.isError || settings.data === undefined ? (
        <ErrorAlert error={settings.error} />
      ) : (
        <CompanyForm initial={settings.data} />
      )}
      <SequencesCard />
      <ExchangeRatesCard />
      <TaxRatesCard />
    </div>
  );
}
