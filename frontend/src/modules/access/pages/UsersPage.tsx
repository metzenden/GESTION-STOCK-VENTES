import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { api, fieldErrors } from '@/api/client';
import type { components } from '@/api/schema';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { SelectField, TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { useCan, useMe } from '@/modules/auth/session';

type User = components['schemas']['User'];
type Role = components['schemas']['Role'];

function useRoles() {
  return useQuery({ queryKey: ['roles'], queryFn: async () => (await api.GET('/roles')).data?.data ?? [] });
}

function InviteForm({ roles }: { roles: Role[] }) {
  const queryClient = useQueryClient();
  const [email, setEmail] = useState('');
  const [roleId, setRoleId] = useState('');

  const invite = useMutation({
    mutationFn: async () => api.POST('/invitations', { body: { email, role_id: roleId } }),
    onSuccess: () => {
      setEmail('');
      void queryClient.invalidateQueries({ queryKey: ['invitations'] });
    },
  });

  const errors = fieldErrors(invite.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    invite.mutate();
  };

  return (
    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end" noValidate>
      <TextField
        label="Adresse e-mail"
        type="email"
        error={errors.email}
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      <SelectField
        label="Rôle"
        error={errors.role_id}
        value={roleId}
        onChange={(e) => setRoleId(e.target.value)}
      >
        <option value="">Choisir…</option>
        {roles.map((role) => (
          <option key={role.id} value={role.id}>
            {role.name}
          </option>
        ))}
      </SelectField>
      <Button type="submit" loading={invite.isPending}>
        Inviter
      </Button>
      <div className="sm:col-span-3">
        {invite.isSuccess && (
          <Alert tone="success">Invitation envoyée : le lien est valable 72 heures.</Alert>
        )}
        {Object.keys(errors).length === 0 && <ErrorAlert error={invite.error} />}
      </div>
    </form>
  );
}

function Invitations() {
  const queryClient = useQueryClient();
  const canManage = useCan('users.manage');
  const invitations = useQuery({
    queryKey: ['invitations'],
    queryFn: async () => (await api.GET('/invitations')).data?.data ?? [],
  });
  const revoke = useMutation({
    mutationFn: async (id: string) => api.POST('/invitations/{id}/revoke', { params: { path: { id } } }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['invitations'] }),
  });

  const pending = (invitations.data ?? []).filter((i) => i.status === 'pending');
  if (pending.length === 0) return null;

  return (
    <Card title="Invitations en attente">
      <ErrorAlert error={revoke.error} />
      <ul className="divide-y divide-slate-200 dark:divide-slate-800">
        {pending.map((invitation) => (
          <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <span>
              {invitation.email} · {invitation.role.name} · expire le {formatDateTime(invitation.expires_at)}
            </span>
            {canManage && (
              <Button
                variant="ghost"
                onClick={() => revoke.mutate(invitation.id)}
                loading={revoke.isPending && revoke.variables === invitation.id}
              >
                Révoquer
              </Button>
            )}
          </li>
        ))}
      </ul>
    </Card>
  );
}

function UserRow({ user, roles }: { user: User; roles: Role[] }) {
  const queryClient = useQueryClient();
  const { data: me } = useMe();
  const canManage = useCan('users.manage');
  const [roleId, setRoleId] = useState(user.roles[0]?.id ?? '');
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['users'] });

  const updateRole = useMutation({
    mutationFn: async (id: string) =>
      api.PATCH('/users/{id}', { params: { path: { id: user.id } }, body: { role_ids: [id] } }),
    onSuccess: refresh,
    onError: () => setRoleId(user.roles[0]?.id ?? ''),
  });
  const toggle = useMutation({
    mutationFn: async () =>
      user.status === 'disabled'
        ? api.POST('/users/{id}/enable', { params: { path: { id: user.id } } })
        : api.POST('/users/{id}/disable', { params: { path: { id: user.id } } }),
    onSuccess: refresh,
  });

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium">
            {user.name} {user.status === 'disabled' && <Badge tone="danger">Désactivé</Badge>}
          </p>
          <p className="truncate text-xs text-slate-500">
            {user.email} · dernière connexion : {formatDateTime(user.last_login_at)}
          </p>
        </div>
        {canManage ? (
          <div className="flex flex-wrap items-center gap-2">
            <select
              aria-label={`Rôle de ${user.name}`}
              className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
              value={roleId}
              onChange={(e) => {
                setRoleId(e.target.value);
                updateRole.mutate(e.target.value);
              }}
            >
              {roles.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.name}
                </option>
              ))}
            </select>
            {user.id !== me?.user.id && (
              <Button
                variant={user.status === 'disabled' ? 'secondary' : 'ghost'}
                onClick={() => toggle.mutate()}
                loading={toggle.isPending}
              >
                {user.status === 'disabled' ? 'Réactiver' : 'Désactiver'}
              </Button>
            )}
          </div>
        ) : (
          <span className="text-sm text-slate-600">{user.roles.map((r) => r.name).join(', ')}</span>
        )}
      </div>
      <div className="mt-2 space-y-2">
        <ErrorAlert error={updateRole.error} />
        <ErrorAlert error={toggle.error} />
      </div>
    </li>
  );
}

export function UsersPage() {
  const canManage = useCan('users.manage');
  const roles = useRoles();
  const users = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.GET('/users', { params: { query: { per_page: 100 } } })).data,
  });

  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <PageHeader
        title="Utilisateurs"
        description="Un compte appartient à une seule entreprise. Désactiver un compte ferme immédiatement ses sessions."
      />
      {canManage && (
        <Card title="Inviter un utilisateur">
          <InviteForm roles={roles.data ?? []} />
        </Card>
      )}
      <Invitations />
      <Card title="Comptes">
        {users.isPending ? (
          <Spinner />
        ) : users.isError ? (
          <ErrorAlert error={users.error} />
        ) : (
          <ul className="divide-y divide-slate-200 dark:divide-slate-800">
            {users.data?.data.map((user) => (
              <UserRow key={user.id} user={user} roles={roles.data ?? []} />
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
