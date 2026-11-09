import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export const methodLabels: Record<string, string> = {
  cash: 'Espèces',
  wave: 'Wave',
  orange_money: 'Orange Money',
  bank_transfer: 'Virement',
};

/** Type de compte attendu pour chaque moyen de paiement (PAY-004). */
export const accountTypeFor: Record<string, string> = {
  cash: 'cash',
  wave: 'wave',
  orange_money: 'orange_money',
  bank_transfer: 'bank',
};

export function useFinancialAccounts() {
  return useQuery({
    queryKey: ['financial-accounts'],
    queryFn: async () => (await api.GET('/financial-accounts')).data?.data ?? [],
  });
}

export function useCustomers(term: string) {
  return useQuery({
    queryKey: ['customers', term],
    queryFn: async () =>
      (
        await api.GET('/customers', {
          params: { query: { per_page: 20, ...(term !== '' ? { q: term } : {}) } },
        })
      ).data?.data ?? [],
  });
}
