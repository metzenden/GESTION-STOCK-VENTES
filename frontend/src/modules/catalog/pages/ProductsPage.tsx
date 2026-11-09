import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageOff } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { api, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';

export const productStatusLabels: Record<string, { label: string; tone: 'success' | 'warning' | 'neutral' }> =
  {
    active: { label: 'Actif', tone: 'success' },
    inactive: { label: 'Inactif', tone: 'warning' },
    archived: { label: 'Archivé', tone: 'neutral' },
  };

export function useCategories() {
  return useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.GET('/categories')).data?.data ?? [],
  });
}

/** Libellé indenté selon la profondeur du chemin matérialisé. */
export function categoryOptionLabel(path: string, name: string): string {
  const depth = path.split('/').filter((s) => s !== '').length - 1;
  return `${'  '.repeat(depth)}${depth > 0 ? '└ ' : ''}${name}`;
}

function CreateProductForm({ onCancel }: { onCancel: () => void }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const categories = useCategories();
  const [form, setForm] = useState({
    reference: '',
    name: '',
    category_id: '',
    product_type: 'stock',
    pieces_per_carton: '',
    barcode: '',
  });

  const create = useMutation({
    mutationFn: async () =>
      (
        await api.POST('/products', {
          body: {
            reference: form.reference,
            name: form.name,
            category_id: form.category_id === '' ? null : form.category_id,
            product_type: form.product_type as 'stock' | 'service',
            pieces_per_carton:
              form.pieces_per_carton === '' ? null : Number.parseInt(form.pieces_per_carton, 10),
            barcode: form.barcode === '' ? null : form.barcode,
          },
        })
      ).data?.data,
    onSuccess: (product) => {
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      if (product !== undefined) void navigate(`/produits/${product.id}`);
    },
  });

  const errors = fieldErrors(create.error);
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) =>
    setForm({ ...form, [key]: e.target.value });
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
        label="Référence"
        error={errors.reference}
        value={form.reference}
        onChange={set('reference')}
      />
      <TextField label="Nom" error={errors.name} value={form.name} onChange={set('name')} />
      <SelectField label="Catégorie" value={form.category_id} onChange={set('category_id')}>
        <option value="">— Aucune —</option>
        {(categories.data ?? []).map((c) => (
          <option key={c.id} value={c.id}>
            {categoryOptionLabel(c.path, c.name)}
          </option>
        ))}
      </SelectField>
      <SelectField label="Type" value={form.product_type} onChange={set('product_type')}>
        <option value="stock">Article stocké</option>
        <option value="service">Prestation (sans stock)</option>
      </SelectField>
      <TextField
        label="Pièces par carton"
        type="number"
        min={1}
        hint="Laisser vide si vendu à la pièce uniquement"
        error={errors.pieces_per_carton}
        value={form.pieces_per_carton}
        onChange={set('pieces_per_carton')}
      />
      <TextField
        label="Code-barres (pièce)"
        error={errors.barcode}
        value={form.barcode}
        onChange={set('barcode')}
      />
      <div className="flex gap-2 sm:col-span-2">
        <Button type="submit" loading={create.isPending}>
          Créer le produit
        </Button>
        <Button variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
      </div>
    </form>
  );
}

export function ProductsPage() {
  const canCreate = useCan('products.create');
  const categories = useCategories();
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('active');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);

  const products = useQuery({
    queryKey: ['products', search, status, category, page],
    queryFn: async () =>
      (
        await api.GET('/products', {
          params: {
            query: {
              page,
              per_page: 30,
              ...(search !== '' ? { q: search } : {}),
              'filter[status]': status as 'active' | 'inactive' | 'archived',
              ...(category !== '' ? { 'filter[category_id]': category } : {}),
            },
          },
        })
      ).data,
    placeholderData: (previous) => previous,
  });

  return (
    <div className="mx-auto max-w-6xl space-y-4">
      <PageHeader
        title="Produits"
        description="Catalogue : produits, variantes, conditionnements et prix."
        actions={canCreate && !creating && <Button onClick={() => setCreating(true)}>Nouveau produit</Button>}
      />
      {creating && (
        <Card title="Nouveau produit">
          <CreateProductForm onCancel={() => setCreating(false)} />
        </Card>
      )}
      <Card>
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <TextField
            label="Rechercher"
            placeholder="Nom, référence, SKU, code-barres"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
          <SelectField
            label="Catégorie"
            value={category}
            onChange={(e) => {
              setCategory(e.target.value);
              setPage(1);
            }}
          >
            <option value="">Toutes</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {categoryOptionLabel(c.path, c.name)}
              </option>
            ))}
          </SelectField>
          <SelectField
            label="Statut"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="active">Actifs</option>
            <option value="inactive">Inactifs</option>
            <option value="archived">Archivés</option>
          </SelectField>
        </div>
        {products.isPending ? (
          <Spinner />
        ) : products.isError ? (
          <ErrorAlert error={products.error} />
        ) : products.data?.data.length === 0 ? (
          <p className="text-sm text-slate-500">Aucun produit.</p>
        ) : (
          <>
            <ul className="divide-y divide-slate-200 dark:divide-slate-800">
              {products.data?.data.map((p) => (
                <li key={p.id} className="flex items-center gap-3 py-2">
                  {p.thumbnail_url !== null ? (
                    <img
                      src={p.thumbnail_url}
                      alt=""
                      loading="lazy"
                      className="size-12 shrink-0 rounded object-cover"
                    />
                  ) : (
                    <span className="flex size-12 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400 dark:bg-slate-800">
                      <ImageOff className="size-5" aria-hidden />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <Link
                      to={`/produits/${p.id}`}
                      className="font-medium text-brand-700 hover:underline dark:text-brand-500"
                    >
                      {p.name}
                    </Link>
                    <p className="truncate text-xs text-slate-500">
                      {p.reference} {p.category !== null && `· ${p.category.name}`}{' '}
                      {p.product_type === 'service' && '· prestation'}
                    </p>
                  </div>
                  <Badge tone={productStatusLabels[p.status]?.tone ?? 'neutral'}>
                    {productStatusLabels[p.status]?.label ?? p.status}
                  </Badge>
                </li>
              ))}
            </ul>
            <div className="mt-3 flex items-center justify-between text-sm">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Précédent
              </Button>
              <span>
                {products.data?.meta.total} produit(s) · page {products.data?.meta.page} /{' '}
                {products.data?.meta.last_page}
              </span>
              <Button
                variant="secondary"
                disabled={page >= (products.data?.meta.last_page ?? 1)}
                onClick={() => setPage(page + 1)}
              >
                Suivant
              </Button>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
