import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/api/client';
import type { components } from '@/api/schema';

export type Me = components['schemas']['Me'];

export const meQueryKey = ['auth', 'me'] as const;

/** Profil courant ; null si personne n'est connecté (401). */
export function useMe() {
  return useQuery({
    queryKey: meQueryKey,
    queryFn: async (): Promise<Me | null> => {
      try {
        const { data } = await api.GET('/auth/me');
        return data?.data ?? null;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
  });
}

export function hasPermission(me: Me | null | undefined, permission: string): boolean {
  return me?.permissions.includes(permission) ?? false;
}

export function useCan(permission: string): boolean {
  return hasPermission(useMe().data, permission);
}

/** Après connexion, changement d'entreprise ou déconnexion : tout le cache dépend du contexte. */
export function useResetSession() {
  const queryClient = useQueryClient();

  return (me: Me | null) => {
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' });
    queryClient.setQueryData(meQueryKey, me);
  };
}

export function useLogout() {
  const reset = useResetSession();

  return useMutation({
    mutationFn: async () => {
      await api.POST('/auth/logout');
    },
    onSettled: () => reset(null),
  });
}
