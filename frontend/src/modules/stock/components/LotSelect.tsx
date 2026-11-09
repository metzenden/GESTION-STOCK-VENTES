import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

/** Lots ayant une quantité libre pour une variante dans un dépôt (sorties d'ajustement). */
export function LotSelect({
  variantId,
  warehouseId,
  value,
  onChange,
}: {
  variantId: string;
  warehouseId: string;
  value: string;
  onChange: (lotId: string) => void;
}) {
  const lots = useQuery({
    queryKey: ['stock', 'lots', variantId, warehouseId],
    enabled: variantId !== '' && warehouseId !== '',
    queryFn: async () =>
      (
        await api.GET('/stock/lots', {
          params: {
            query: { 'filter[variant_id]': variantId, 'filter[warehouse_id]': warehouseId, per_page: 50 },
          },
        })
      ).data?.data ?? [],
  });

  return (
    <select
      aria-label="Lot"
      className="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">Choisir un lot…</option>
      {(lots.data ?? []).map((l) => (
        <option key={l.id} value={l.id}>
          {l.lot_number} — libre {l.free}
        </option>
      ))}
    </select>
  );
}
