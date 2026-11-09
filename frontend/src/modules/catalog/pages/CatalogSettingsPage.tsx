import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { api, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';
import { categoryOptionLabel, useCategories } from '@/modules/catalog/pages/ProductsPage';

function CategoriesCard() {
  const canEdit = useCan('products.update');
  const queryClient = useQueryClient();
  const categories = useCategories();
  const [name, setName] = useState('');
  const [parent, setParent] = useState('');
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['categories'] });
  const add = useMutation({
    mutationFn: async () =>
      api.POST('/categories', { body: { name, parent_id: parent === '' ? null : parent } }),
    onSuccess: () => {
      setName('');
      refresh();
    },
  });
  const remove = useMutation({
    mutationFn: async (id: string) => api.DELETE('/categories/{id}', { params: { path: { id } } }),
    onSuccess: refresh,
  });
  const errors = fieldErrors(add.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    add.mutate();
  };

  return (
    <Card title="Catégories">
      <ErrorAlert error={remove.error} />
      <ul className="my-2 text-sm">
        {(categories.data ?? []).map((c) => (
          <li key={c.id} className="flex items-center justify-between py-1">
            <span className="whitespace-pre">{categoryOptionLabel(c.path, c.name)}</span>
            {canEdit && (
              <Button variant="ghost" onClick={() => remove.mutate(c.id)}>
                Supprimer
              </Button>
            )}
          </li>
        ))}
      </ul>
      {canEdit && (
        <form onSubmit={submit} className="grid gap-2 sm:grid-cols-3 sm:items-end" noValidate>
          <TextField
            label="Nouvelle catégorie"
            error={errors.name}
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
          <SelectField label="Dans" value={parent} onChange={(e) => setParent(e.target.value)}>
            <option value="">— Racine —</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {categoryOptionLabel(c.path, c.name)}
              </option>
            ))}
          </SelectField>
          <Button type="submit" variant="secondary" loading={add.isPending}>
            Ajouter
          </Button>
        </form>
      )}
    </Card>
  );
}

function AttributesCard() {
  const canEdit = useCan('products.update');
  const queryClient = useQueryClient();
  const attributes = useQuery({
    queryKey: ['attributes'],
    queryFn: async () => (await api.GET('/attributes')).data?.data ?? [],
  });
  const [name, setName] = useState('');
  const [values, setValues] = useState<Record<string, string>>({});
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['attributes'] });
  const add = useMutation({
    mutationFn: async () => api.POST('/attributes', { body: { name } }),
    onSuccess: () => {
      setName('');
      refresh();
    },
  });
  const addValue = useMutation({
    mutationFn: async (id: string) =>
      api.POST('/attributes/{id}/values', { params: { path: { id } }, body: { value: values[id] ?? '' } }),
    onSuccess: (_, id) => {
      setValues({ ...values, [id]: '' });
      refresh();
    },
  });

  return (
    <Card title="Attributs de variantes">
      <ErrorAlert error={add.error ?? addValue.error} />
      <div className="my-2 space-y-3">
        {(attributes.data ?? []).map((a) => (
          <div key={a.id}>
            <p className="text-sm font-medium">{a.name}</p>
            <div className="mt-1 flex flex-wrap items-center gap-1">
              {a.values.map((v) => (
                <Badge key={v.id}>{v.value}</Badge>
              ))}
              {canEdit && (
                <form
                  className="flex items-center gap-1"
                  onSubmit={(e) => {
                    e.preventDefault();
                    addValue.mutate(a.id);
                  }}
                >
                  <input
                    aria-label={`Nouvelle valeur pour ${a.name}`}
                    className="w-28 rounded border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-900"
                    placeholder="Valeur"
                    value={values[a.id] ?? ''}
                    onChange={(e) => setValues({ ...values, [a.id]: e.target.value })}
                  />
                  <Button type="submit" variant="ghost">
                    +
                  </Button>
                </form>
              )}
            </div>
          </div>
        ))}
      </div>
      {canEdit && (
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
        >
          <div className="min-w-48 flex-1">
            <TextField
              label="Nouvel attribut (ex. Couleur, Taille)"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>
          <Button type="submit" variant="secondary">
            Ajouter
          </Button>
        </form>
      )}
    </Card>
  );
}

function PriceListsCard() {
  const lists = useQuery({
    queryKey: ['price-lists'],
    queryFn: async () => (await api.GET('/price-lists')).data?.data ?? [],
  });

  return (
    <Card title="Grilles tarifaires">
      <ul className="text-sm">
        {(lists.data ?? []).map((l) => (
          <li key={l.id} className="py-1">
            {l.name}{' '}
            <span className="text-slate-500">
              ({l.code}, {l.currency})
            </span>{' '}
            {l.is_default && <Badge tone="success">par défaut</Badge>}
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function CatalogSettingsPage() {
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader
        title="Organisation du catalogue"
        description="Catégories, attributs de variantes et grilles tarifaires."
      />
      <CategoriesCard />
      <AttributesCard />
      <PriceListsCard />
    </div>
  );
}
