import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export const supplierTypeLabels: Record<string, string> = {
  goods: 'Marchandises',
  forwarder: 'Transitaire',
  carrier: 'Transporteur',
  customs: 'Douane',
  other: 'Autre',
};

export function useSuppliers(type?: string) {
  return useQuery({
    queryKey: ['suppliers', type ?? 'all'],
    queryFn: async () =>
      (
        await api.GET('/suppliers', {
          params: {
            query: { per_page: 100, ...(type !== undefined ? { 'filter[supplier_type]': type } : {}) },
          },
        })
      ).data?.data ?? [],
  });
}

export function useCurrencies(): string[] {
  return ['XOF', 'EUR', 'USD', 'CNY'];
}

/** Saisie décimale tolérante (« 12,5 » → « 12.5 ») ; l'API attend des chaînes. */
export function decimalInput(value: string): string {
  return value.replace(/\s/g, '').replace(',', '.');
}

/** Prix unitaire exact sans zéros inutiles (« 1.85000000 » → « 1.85 »), au moins la précision de la devise. */
export function unitPrice(amount: string, currency: string): { amount: string; currency: string } {
  const [int = '0', frac = ''] = amount.split('.');
  const minimum = currency === 'XOF' ? 0 : 2;
  const trimmed = frac.replace(/0+$/, '').padEnd(minimum, '0');
  return { amount: trimmed === '' ? int : `${int}.${trimmed}`, currency };
}
