import { useMutation, useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useMe, useResetSession } from '@/modules/auth/session';

/** Sélecteur d'entreprise active du super-admin (audité côté serveur, MT-008). */
export function CompanySwitcher() {
  const { data: me } = useMe();
  const reset = useResetSession();

  const companies = useQuery({
    queryKey: ['admin', 'companies', 'switcher'],
    queryFn: async () =>
      (await api.GET('/admin/companies', { params: { query: { per_page: 100 } } })).data?.data ?? [],
  });

  const switchTo = useMutation({
    mutationFn: async (companyId: string | null) => {
      await api.POST('/context/company', { body: { company_id: companyId } });
      return (await api.GET('/auth/me')).data?.data ?? null;
    },
    onSuccess: (profile) => reset(profile),
  });

  return (
    <label className="flex min-w-0 items-center gap-2 text-sm">
      <span className="hidden text-slate-500 md:inline">Entreprise</span>
      <select
        className="min-w-0 max-w-48 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
        value={me?.company?.id ?? ''}
        disabled={switchTo.isPending}
        onChange={(event) => switchTo.mutate(event.target.value === '' ? null : event.target.value)}
        aria-label="Entreprise active"
      >
        <option value="">— Plateforme —</option>
        {(companies.data ?? []).map((company) => (
          <option key={company.id} value={company.id}>
            {company.trade_name}
            {company.status === 'suspended' ? ' (suspendue)' : ''}
          </option>
        ))}
      </select>
    </label>
  );
}
