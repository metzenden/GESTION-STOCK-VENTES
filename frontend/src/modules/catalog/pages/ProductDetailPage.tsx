import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { api, fieldErrors } from '@/api/client';
import type { components } from '@/api/schema';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { formatMoney } from '@/lib/money';
import { useCan } from '@/modules/auth/session';
import {
  categoryOptionLabel,
  productStatusLabels,
  useCategories,
} from '@/modules/catalog/pages/ProductsPage';

type Product = components['schemas']['Product'];
type Variant = components['schemas']['ProductVariant'];

function useInvalidateProduct(id: string) {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['product', id] });
    void queryClient.invalidateQueries({ queryKey: ['products'] });
  };
}

function GeneralCard({ product }: { product: Product }) {
  const canEdit = useCan('products.update');
  const categories = useCategories();
  const refresh = useInvalidateProduct(product.id);
  const [form, setForm] = useState({
    reference: product.reference,
    name: product.name,
    description: product.description ?? '',
    category_id: product.category?.id ?? '',
  });

  const save = useMutation({
    mutationFn: async () =>
      api.PATCH('/products/{id}', {
        params: { path: { id: product.id }, header: { 'If-Match': `"${product.version}"` } },
        body: {
          ...form,
          description: form.description === '' ? null : form.description,
          category_id: form.category_id === '' ? null : form.category_id,
        },
      }),
    onSuccess: refresh,
  });
  const errors = fieldErrors(save.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <Card title="Informations">
      <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        <TextField
          label="Référence"
          disabled={!canEdit}
          error={errors.reference}
          value={form.reference}
          onChange={(e) => setForm({ ...form, reference: e.target.value })}
        />
        <TextField
          label="Nom"
          disabled={!canEdit}
          error={errors.name}
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
        />
        <SelectField
          label="Catégorie"
          disabled={!canEdit}
          value={form.category_id}
          onChange={(e) => setForm({ ...form, category_id: e.target.value })}
        >
          <option value="">— Aucune —</option>
          {(categories.data ?? []).map((c) => (
            <option key={c.id} value={c.id}>
              {categoryOptionLabel(c.path, c.name)}
            </option>
          ))}
        </SelectField>
        <TextField
          label="Type"
          disabled
          value={product.product_type === 'stock' ? 'Article stocké' : 'Prestation'}
        />
        <div className="sm:col-span-2">
          <TextAreaField
            label="Description"
            disabled={!canEdit}
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
        </div>
        {canEdit && (
          <div className="space-y-2 sm:col-span-2">
            {Object.keys(errors).length === 0 && <ErrorAlert error={save.error} />}
            {save.isSuccess && <Alert tone="success">Produit enregistré.</Alert>}
            <Button type="submit" loading={save.isPending}>
              Enregistrer
            </Button>
          </div>
        )}
      </form>
    </Card>
  );
}

function PricesBlock({ variant }: { variant: Variant }) {
  const canEdit = useCan('products.update');
  const queryClient = useQueryClient();
  const lists = useQuery({
    queryKey: ['price-lists'],
    queryFn: async () => (await api.GET('/price-lists')).data?.data ?? [],
  });
  const prices = useQuery({
    queryKey: ['prices', variant.id],
    queryFn: async () =>
      (await api.GET('/product-variants/{id}/prices', { params: { path: { id: variant.id } } })).data?.data ??
      [],
  });
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({
    price_list_id: '',
    unit_id: variant.units[0]?.id ?? '',
    price: '',
    valid_from: today,
  });
  const add = useMutation({
    mutationFn: async () =>
      api.POST('/product-variants/{id}/prices', {
        params: { path: { id: variant.id } },
        body: {
          ...form,
          price_list_id: form.price_list_id === '' ? (lists.data?.[0]?.id ?? '') : form.price_list_id,
        },
      }),
    onSuccess: () => {
      setForm({ ...form, price: '' });
      void queryClient.invalidateQueries({ queryKey: ['prices', variant.id] });
    },
  });
  const errors = fieldErrors(add.error);
  const listName = new Map((lists.data ?? []).map((l) => [l.id, l.name]));
  const unitName = new Map(variant.units.map((u) => [u.id, u.label]));

  return (
    <div className="space-y-2">
      <h4 className="text-sm font-semibold">Prix</h4>
      {(prices.data ?? []).length === 0 ? (
        <p className="text-sm text-slate-500">Aucun prix.</p>
      ) : (
        <ul className="text-sm">
          {(prices.data ?? []).map((p) => (
            <li key={p.id} className="flex flex-wrap justify-between gap-2 py-0.5">
              <span>
                {listName.get(p.price_list_id)} · {unitName.get(p.unit_id)} :{' '}
                <strong>{formatMoney(p.price)}</strong>
              </span>
              <span className="text-slate-500">
                du {p.valid_from} {p.valid_to !== null ? `au ${p.valid_to}` : '(en vigueur)'}
              </span>
            </li>
          ))}
        </ul>
      )}
      {canEdit && (
        <form
          className="grid gap-2 sm:grid-cols-5 sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
          noValidate
        >
          <SelectField
            label="Grille"
            value={form.price_list_id}
            onChange={(e) => setForm({ ...form, price_list_id: e.target.value })}
          >
            {(lists.data ?? []).map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </SelectField>
          <SelectField
            label="Unité"
            value={form.unit_id}
            onChange={(e) => setForm({ ...form, unit_id: e.target.value })}
          >
            {variant.units.map((u) => (
              <option key={u.id} value={u.id}>
                {u.label} (×{u.factor})
              </option>
            ))}
          </SelectField>
          <TextField
            label="Prix"
            inputMode="decimal"
            error={errors.price}
            value={form.price}
            onChange={(e) => setForm({ ...form, price: e.target.value.replace(',', '.') })}
          />
          <TextField
            label="À partir du"
            type="date"
            error={errors.valid_from}
            value={form.valid_from}
            onChange={(e) => setForm({ ...form, valid_from: e.target.value })}
          />
          <Button type="submit" variant="secondary" loading={add.isPending}>
            Ajouter
          </Button>
          <div className="sm:col-span-5">
            {Object.keys(errors).length === 0 && <ErrorAlert error={add.error} />}
          </div>
        </form>
      )}
    </div>
  );
}

function UnitsBlock({ variant, productId }: { variant: Variant; productId: string }) {
  const canEdit = useCan('products.update');
  const refresh = useInvalidateProduct(productId);
  const [form, setForm] = useState({ code: '', label: '', factor: '', barcode: '' });
  const add = useMutation({
    mutationFn: async () =>
      api.POST('/product-variants/{id}/units', {
        params: { path: { id: variant.id } },
        body: {
          code: form.code.toLowerCase(),
          label: form.label,
          factor: Number.parseInt(form.factor || '0', 10),
          barcode: form.barcode === '' ? null : form.barcode,
        },
      }),
    onSuccess: () => {
      setForm({ code: '', label: '', factor: '', barcode: '' });
      refresh();
    },
  });
  const errors = fieldErrors(add.error);

  return (
    <div className="space-y-2">
      <h4 className="text-sm font-semibold">Conditionnements et codes-barres</h4>
      <ul className="text-sm">
        {variant.units.map((u) => (
          <li key={u.id} className="flex flex-wrap justify-between gap-2 py-0.5">
            <span>
              {u.label} = {u.factor} pièce(s) {u.locked && <Badge>figé</Badge>}
            </span>
            <span className="font-mono text-xs text-slate-500">{u.barcode ?? 'sans code-barres'}</span>
          </li>
        ))}
      </ul>
      {canEdit && (
        <form
          className="grid gap-2 sm:grid-cols-5 sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
          noValidate
        >
          <TextField
            label="Code"
            placeholder="paquet"
            error={errors.code}
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value })}
          />
          <TextField
            label="Libellé"
            error={errors.label}
            value={form.label}
            onChange={(e) => setForm({ ...form, label: e.target.value })}
          />
          <TextField
            label="Pièces"
            type="number"
            min={1}
            error={errors.factor}
            value={form.factor}
            onChange={(e) => setForm({ ...form, factor: e.target.value })}
          />
          <TextField
            label="Code-barres"
            error={errors.barcode}
            value={form.barcode}
            onChange={(e) => setForm({ ...form, barcode: e.target.value })}
          />
          <Button type="submit" variant="secondary" loading={add.isPending}>
            Ajouter
          </Button>
        </form>
      )}
    </div>
  );
}

function VariantsCard({ product }: { product: Product }) {
  const canEdit = useCan('products.update');
  const refresh = useInvalidateProduct(product.id);
  const [form, setForm] = useState({ sku: '', label: '' });
  const add = useMutation({
    mutationFn: async () =>
      api.POST('/products/{id}/variants', { params: { path: { id: product.id } }, body: form }),
    onSuccess: () => {
      setForm({ sku: '', label: '' });
      refresh();
    },
  });
  const errors = fieldErrors(add.error);

  return (
    <Card title={product.has_variants ? `Variantes (${product.variants.length})` : 'Variante'}>
      <div className="space-y-6">
        {product.variants.map((v) => (
          <div key={v.id} className="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-800">
            <p className="font-medium">
              {v.label} <span className="text-sm text-slate-500">· SKU {v.sku}</span>{' '}
              {v.status === 'inactive' && <Badge tone="warning">inactive</Badge>}
            </p>
            <UnitsBlock variant={v} productId={product.id} />
            <PricesBlock variant={v} />
          </div>
        ))}
        {canEdit && (
          <form
            className="grid gap-2 sm:grid-cols-3 sm:items-end"
            onSubmit={(e) => {
              e.preventDefault();
              add.mutate();
            }}
            noValidate
          >
            <TextField
              label="SKU de la nouvelle variante"
              error={errors.sku}
              value={form.sku}
              onChange={(e) => setForm({ ...form, sku: e.target.value })}
            />
            <TextField
              label="Libellé (ex. Rouge / XL)"
              value={form.label}
              onChange={(e) => setForm({ ...form, label: e.target.value })}
            />
            <Button type="submit" variant="secondary" loading={add.isPending}>
              Ajouter une variante
            </Button>
          </form>
        )}
      </div>
    </Card>
  );
}

function ImagesCard({ product }: { product: Product }) {
  const canEdit = useCan('products.update');
  const refresh = useInvalidateProduct(product.id);
  const upload = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append('image', file);
      return api.POST('/products/{id}/images', {
        params: { path: { id: product.id } },
        body: form as unknown as { image: string },
        bodySerializer: (body) => body as unknown as FormData,
      });
    },
    onSuccess: refresh,
  });
  const remove = useMutation({
    mutationFn: async (id: string) => api.DELETE('/product-images/{id}', { params: { path: { id } } }),
    onSuccess: refresh,
  });

  return (
    <Card title="Photos">
      <ErrorAlert error={upload.error ?? remove.error} />
      <div className="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-5">
        {product.images.map((image) => (
          <div key={image.id} className="relative">
            <a href={image.url} target="_blank" rel="noreferrer">
              <img
                src={image.thumbnail_url}
                alt=""
                loading="lazy"
                className="aspect-square w-full rounded object-cover"
              />
            </a>
            {canEdit && (
              <button
                type="button"
                onClick={() => remove.mutate(image.id)}
                className="absolute right-1 top-1 rounded bg-white/90 p-1 text-red-700 dark:bg-slate-900/90"
                aria-label="Supprimer la photo"
              >
                <Trash2 className="size-4" />
              </button>
            )}
          </div>
        ))}
      </div>
      {canEdit && (
        <label className="mt-3 inline-flex cursor-pointer items-center gap-2 text-sm text-brand-700 dark:text-brand-500">
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="sr-only"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file !== undefined) upload.mutate(file);
              e.target.value = '';
            }}
          />
          {upload.isPending ? 'Envoi…' : '+ Ajouter une photo (JPEG, PNG ou WebP, 5 Mo max.)'}
        </label>
      )}
    </Card>
  );
}

export function ProductDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const canEdit = useCan('products.update');
  const refresh = useInvalidateProduct(id);
  const product = useQuery({
    queryKey: ['product', id],
    queryFn: async () => (await api.GET('/products/{id}', { params: { path: { id } } })).data?.data,
  });
  const status = useMutation({
    mutationFn: async (action: 'archive' | 'activate' | 'deactivate') => {
      const paths = {
        archive: '/products/{id}/archive',
        activate: '/products/{id}/activate',
        deactivate: '/products/{id}/deactivate',
      } as const;
      return api.POST(paths[action], { params: { path: { id } } });
    },
    onSuccess: refresh,
  });
  const remove = useMutation({
    mutationFn: async () => api.DELETE('/products/{id}', { params: { path: { id } } }),
    onSuccess: () => void navigate('/produits'),
  });

  if (product.isPending) return <Spinner />;
  if (product.isError || product.data === undefined) return <ErrorAlert error={product.error} />;
  const p = product.data;
  const s = productStatusLabels[p.status];

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <Link to="/produits" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Produits
      </Link>
      <PageHeader
        title={p.name}
        description={`Référence ${p.reference}${p.has_history ? ' · produit avec historique (suppression impossible)' : ''}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={s?.tone ?? 'neutral'}>{s?.label ?? p.status}</Badge>
            {canEdit && p.status !== 'active' && (
              <Button variant="secondary" onClick={() => status.mutate('activate')}>
                Activer
              </Button>
            )}
            {canEdit && p.status === 'active' && (
              <Button variant="secondary" onClick={() => status.mutate('deactivate')}>
                Désactiver
              </Button>
            )}
            {canEdit && p.status !== 'archived' && (
              <Button variant="ghost" onClick={() => status.mutate('archive')}>
                Archiver
              </Button>
            )}
            {canEdit && !p.has_history && (
              <Button variant="danger" onClick={() => remove.mutate()} loading={remove.isPending}>
                Supprimer
              </Button>
            )}
          </div>
        }
      />
      <ErrorAlert error={status.error ?? remove.error} />
      <GeneralCard key={p.version} product={p} />
      <VariantsCard product={p} />
      <ImagesCard product={p} />
    </div>
  );
}
