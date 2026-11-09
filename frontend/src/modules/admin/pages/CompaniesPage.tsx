import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { api, fieldErrors, newIdempotencyKey } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';

const statusLabels: Record<string, { label: string; tone: 'success' | 'warning' | 'neutral' | 'danger' }> = {
  active: { label: 'Active', tone: 'success' },
  suspended: { label: 'Suspendue', tone: 'danger' },
  provisioning: { label: 'En création', tone: 'warning' },
  archived: { label: 'Archivée', tone: 'neutral' },
};

export function CompanyStatusBadge({ status }: { status: string }) {
  const s = statusLabels[status] ?? { label: status, tone: 'neutral' as const };
  return <Badge tone={s.tone}>{s.label}</Badge>;
}

const emptyForm = { code: '', trade_name: '', reference_currency: 'XOF', admin_email: '', city: '' };

function CreateCompanyForm({ onDone }: { onDone: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState(emptyForm);
  // Une seule clé par intention : un double clic ou un nouvel essai ne crée pas deux entreprises.
  const idempotencyKey = useRef(newIdempotencyKey());

  const create = useMutation({
    mutationFn: async () => {
      const { data } = await api.POST('/admin/companies', {
        params: { header: { 'Idempotency-Key': idempotencyKey.current } },
        body: { ...form, code: form.code.toUpperCase(), city: form.city === '' ? null : form.city },
      });
      return data?.data;
    },
    onSuccess: () => {
      idempotencyKey.current = newIdempotencyKey();
      setForm(emptyForm);
      void queryClient.invalidateQueries({ queryKey: ['admin', 'companies'] });
      onDone();
    },
  });

  const errors = fieldErrors(create.error);
  const set = (key: keyof typeof emptyForm) => (event: { target: { value: string } }) =>
    setForm({ ...form, [key]: event.target.value });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
      <div className="sm:col-span-2">
        {Object.keys(errors).length === 0 && <ErrorAlert error={create.error} />}
      </div>
      <TextField
        label="Code"
        hint="Lettres majuscules, chiffres, - et _ (ex. FDS)"
        error={errors.code}
        value={form.code}
        onChange={set('code')}
      />
      <TextField
        label="Nom commercial"
        error={errors.trade_name}
        value={form.trade_name}
        onChange={set('trade_name')}
      />
      <SelectField
        label="Devise de référence"
        error={errors.reference_currency}
        value={form.reference_currency}
        onChange={set('reference_currency')}
      >
        <option value="XOF">XOF — Franc CFA</option>
        <option value="EUR">EUR — Euro</option>
        <option value="USD">USD — Dollar américain</option>
        <option value="CNY">CNY — Yuan</option>
      </SelectField>
      <TextField label="Ville" error={errors.city} value={form.city} onChange={set('city')} />
      <TextField
        label="E-mail de l'administrateur"
        type="email"
        hint="Une invitation lui sera envoyée (valable 72 h)."
        error={errors.admin_email}
        value={form.admin_email}
        onChange={set('admin_email')}
      />
      <div className="flex items-end gap-2">
        <Button type="submit" loading={create.isPending}>
          Créer l'entreprise
        </Button>
        <Button variant="ghost" onClick={onDone}>
          Annuler
        </Button>
      </div>
    </form>
  );
}

export function CompaniesPage() {
  const [search, setSearch] = useState('');
  const [creating, setCreating] = useState(false);
  const [created, setCreated] = useState(false);

  const companies = useQuery({
    queryKey: ['admin', 'companies', search],
    queryFn: async () =>
      (
        await api.GET('/admin/companies', {
          params: { query: { per_page: 50, ...(search !== '' ? { q: search } : {}) } },
        })
      ).data,
  });

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Entreprises"
        description="Création, suspension et réactivation des entreprises hébergées."
        actions={!creating && <Button onClick={() => setCreating(true)}>Nouvelle entreprise</Button>}
      />
      {created && (
        <Alert tone="success">Entreprise créée. L'administrateur a reçu son invitation par e-mail.</Alert>
      )}
      {creating && (
        <Card title="Nouvelle entreprise">
          <CreateCompanyForm
            onDone={() => {
              setCreating(false);
              setCreated(true);
            }}
          />
        </Card>
      )}
      <Card>
        <div className="mb-4 max-w-sm">
          <TextField
            label="Rechercher"
            placeholder="Nom ou code"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        {companies.isPending ? (
          <Spinner />
        ) : companies.isError ? (
          <ErrorAlert error={companies.error} />
        ) : companies.data?.data.length === 0 ? (
          <p className="text-sm text-slate-500">Aucune entreprise.</p>
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {companies.data?.data.map((company) => (
              <li key={company.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                <div className="min-w-0">
                  <Link
                    to={`/admin/entreprises/${company.id}`}
                    className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                  >
                    {company.trade_name}
                  </Link>
                  <p className="text-xs text-slate-500">
                    {company.code} · {company.reference_currency} · {company.city ?? '—'}
                  </p>
                </div>
                <CompanyStatusBadge status={company.status} />
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
