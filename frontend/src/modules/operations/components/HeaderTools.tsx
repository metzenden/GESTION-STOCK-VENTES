import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, Search } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { api } from '@/api/client';

/** Recherche globale (L6-09) et cloche de notifications (L6-03), dans l'en-tête de l'application. */
export function HeaderTools() {
  const queryClient = useQueryClient();
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState<'search' | 'bell' | null>(null);
  const notifications = useQuery({
    queryKey: ['notifications'],
    refetchInterval: 60_000,
    queryFn: async () => (await api.GET('/notifications', { params: { query: { per_page: 10 } } })).data,
  });
  const results = useQuery({
    queryKey: ['search', term],
    enabled: term.trim().length >= 2,
    queryFn: async () =>
      (await api.GET('/search', { params: { query: { q: term.trim() } } })).data?.data ?? [],
  });
  const readAll = useMutation({
    mutationFn: async () => api.POST('/notifications/read-all'),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  });
  const unread = Number((notifications.data?.meta as { unread?: number } | undefined)?.unread ?? 0);

  return (
    <div className="relative flex items-center gap-2">
      <div className="relative hidden sm:block">
        <Search className="pointer-events-none absolute left-2 top-2 size-4 text-slate-400" aria-hidden />
        <input
          aria-label="Recherche globale"
          placeholder="Rechercher…"
          className="w-56 rounded-md border border-slate-300 bg-white py-1.5 pl-8 pr-2 text-sm dark:border-slate-700 dark:bg-slate-900"
          value={term}
          onFocus={() => setOpen('search')}
          onChange={(e) => setTerm(e.target.value)}
        />
        {open === 'search' && term.trim().length >= 2 && (
          <ul className="absolute right-0 z-30 mt-1 max-h-80 w-80 overflow-auto rounded-md border border-slate-200 bg-white p-1 text-sm shadow dark:border-slate-700 dark:bg-slate-900">
            {(results.data ?? []).map((r) => (
              <li key={`${String(r.type)}-${String(r.id)}`}>
                <Link
                  to={String(r.link)}
                  onClick={() => setOpen(null)}
                  className="block rounded px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                  {String(r.label)}
                </Link>
              </li>
            ))}
            {(results.data ?? []).length === 0 && (
              <li className="px-2 py-1.5 text-slate-500">Aucun résultat.</li>
            )}
          </ul>
        )}
      </div>
      <button
        type="button"
        aria-label={`Notifications (${unread} non lues)`}
        className="relative rounded p-2"
        onClick={() => setOpen(open === 'bell' ? null : 'bell')}
      >
        <Bell className="size-5" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 rounded-full bg-red-600 px-1.5 text-xs text-white">
            {unread}
          </span>
        )}
      </button>
      {open === 'bell' && (
        <div className="absolute right-0 top-10 z-30 w-80 rounded-md border border-slate-200 bg-white p-2 text-sm shadow dark:border-slate-700 dark:bg-slate-900">
          <ul className="max-h-80 overflow-auto">
            {(notifications.data?.data ?? []).map((n) => (
              <li
                key={String(n.id)}
                className={`rounded px-2 py-1.5 ${n.read_at === null ? 'font-medium' : 'text-slate-500'}`}
              >
                {n.link !== null ? (
                  <Link to={String(n.link)} onClick={() => setOpen(null)}>
                    {String(n.title)}
                  </Link>
                ) : (
                  String(n.title)
                )}
              </li>
            ))}
            {(notifications.data?.data ?? []).length === 0 && (
              <li className="px-2 py-1.5 text-slate-500">Aucune notification.</li>
            )}
          </ul>
          {unread > 0 && (
            <button
              type="button"
              className="mt-2 text-xs text-brand-700 underline"
              onClick={() => readAll.mutate()}
            >
              Tout marquer comme lu
            </button>
          )}
        </div>
      )}
    </div>
  );
}
