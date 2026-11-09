import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/api/client';
import type { components } from '@/api/schema';

export type VariantRef = components['schemas']['VariantRef'];

/**
 * Recherche d'une variante par nom, référence, SKU ou code-barres (lecteur : saisie + Entrée).
 * Un produit à variantes propose ensuite le choix de la variante.
 */
export function VariantPicker({
  onSelect,
  label = 'Article',
}: {
  onSelect: (variant: VariantRef) => void;
  label?: string;
}) {
  const [term, setTerm] = useState('');
  const [productId, setProductId] = useState<string | null>(null);

  const products = useQuery({
    queryKey: ['variant-picker', term],
    enabled: term.trim().length >= 2,
    queryFn: async () =>
      (await api.GET('/products', { params: { query: { q: term.trim(), per_page: 8 } } })).data?.data ?? [],
  });

  const product = useQuery({
    queryKey: ['variant-picker', 'product', productId],
    enabled: productId !== null,
    queryFn: async () =>
      (await api.GET('/products/{id}', { params: { path: { id: productId ?? '' } } })).data?.data,
  });

  const choose = (p: components['schemas']['Product'], v: components['schemas']['ProductVariant']) => {
    onSelect({
      id: v.id,
      product_id: p.id,
      reference: p.reference,
      product_name: p.name,
      sku: v.sku,
      label: v.label,
    });
    setTerm('');
    setProductId(null);
  };

  return (
    <div className="relative space-y-1">
      <label className="block text-sm font-medium text-slate-700 dark:text-slate-300">
        {label}
        <input
          className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900"
          placeholder="Nom, référence, SKU ou code-barres"
          value={term}
          onChange={(e) => {
            setTerm(e.target.value);
            setProductId(null);
          }}
        />
      </label>
      {productId === null && term.trim().length >= 2 && (products.data ?? []).length > 0 && (
        <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
          {(products.data ?? []).map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                onClick={() => setProductId(p.id)}
              >
                <span className="font-medium">{p.name}</span>{' '}
                <span className="text-slate-500">· {p.reference}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {product.data !== undefined && (
        <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
          {product.data.variants
            .filter((v) => v.status === 'active')
            .map((v) => (
              <li key={v.id}>
                <button
                  type="button"
                  className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                  onClick={() => product.data && choose(product.data, v)}
                >
                  {product.data?.name} — {v.label} <span className="text-slate-500">({v.sku})</span>
                </button>
              </li>
            ))}
        </ul>
      )}
    </div>
  );
}

export function variantLabel(v: VariantRef | null | undefined): string {
  if (v === null || v === undefined) return '—';
  return v.label === v.product_name
    ? `${v.product_name} (${v.sku})`
    : `${v.product_name} — ${v.label} (${v.sku})`;
}
