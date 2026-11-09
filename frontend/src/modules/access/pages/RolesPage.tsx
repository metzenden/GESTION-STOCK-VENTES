import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { api, fieldErrors } from '@/api/client';
import type { components } from '@/api/schema';
import { ErrorAlert } from '@/components/ui/Alert';
import { Badge, Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { TextField } from '@/components/ui/Field';
import { useCan } from '@/modules/auth/session';

type Role = components['schemas']['Role'];
type Permission = components['schemas']['Permission'];

const moduleLabels: Record<string, string> = {
  users: 'Utilisateurs',
  roles: 'Rôles',
  settings: 'Paramètres',
  products: 'Produits',
  stock: 'Stocks',
  suppliers: 'Fournisseurs',
  purchasing: 'Achats',
  logistics: 'Arrivages et réceptions',
  costing: 'Coûts de revient',
  customers: 'Clients',
  sales: 'Ventes',
  deliveries: 'Livraisons',
  billing: 'Facturation',
  payments: 'Paiements',
  finance: 'Trésorerie',
  reports: 'Rapports',
  approvals: 'Approbations',
  audit: 'Audit',
};

function RoleEditor({
  role,
  permissions,
  onClose,
}: {
  role: Role | null;
  permissions: Permission[];
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [name, setName] = useState(role?.name ?? '');
  const [selected, setSelected] = useState<Set<string>>(new Set(role?.permissions ?? []));

  const save = useMutation({
    mutationFn: async () => {
      const body = { name, permissions: [...selected] };
      return role === null
        ? api.POST('/roles', { body })
        : api.PATCH('/roles/{id}', { params: { path: { id: role.id } }, body });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['roles'] });
      onClose();
    },
  });

  const toggle = (permission: string) => {
    const next = new Set(selected);
    if (next.has(permission)) next.delete(permission);
    else next.add(permission);
    setSelected(next);
  };

  const groups = permissions.reduce<Record<string, Permission[]>>((acc, p) => {
    (acc[p.module] ??= []).push(p);
    return acc;
  }, {});
  const errors = fieldErrors(save.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {errors.name === undefined && <ErrorAlert error={save.error} />}
      <div className="max-w-sm">
        <TextField
          label="Nom du rôle"
          error={errors.name}
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {Object.entries(groups).map(([module, items]) => (
          <fieldset key={module} className="rounded-md border border-slate-200 p-3 dark:border-slate-800">
            <legend className="px-1 text-sm font-semibold">{moduleLabels[module] ?? module}</legend>
            {items.map((permission) => (
              <label key={permission.name} className="flex items-start gap-2 py-1 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={selected.has(permission.name)}
                  onChange={() => toggle(permission.name)}
                />
                <span>
                  {permission.label} {permission.is_sensitive && <Badge tone="warning">sensible</Badge>}
                </span>
              </label>
            ))}
          </fieldset>
        ))}
      </div>
      <div className="flex gap-2">
        <Button type="submit" loading={save.isPending}>
          Enregistrer
        </Button>
        <Button variant="ghost" onClick={onClose}>
          Annuler
        </Button>
      </div>
    </form>
  );
}

export function RolesPage() {
  const queryClient = useQueryClient();
  const canManage = useCan('roles.manage');
  const [editing, setEditing] = useState<Role | 'new' | null>(null);
  const roles = useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api.GET('/roles')).data?.data ?? [],
  });
  const permissions = useQuery({
    queryKey: ['permissions'],
    queryFn: async () => (await api.GET('/permissions')).data?.data ?? [],
  });
  const remove = useMutation({
    mutationFn: async (id: string) => api.DELETE('/roles/{id}', { params: { path: { id } } }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['roles'] }),
  });

  const labels = new Map((permissions.data ?? []).map((p) => [p.name, p.label]));

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader
        title="Rôles et permissions"
        description="Le rôle Administrateur porte toujours toutes les permissions et ne peut pas être modifié."
        actions={
          canManage && editing === null && <Button onClick={() => setEditing('new')}>Nouveau rôle</Button>
        }
      />
      {editing !== null && (
        <Card title={editing === 'new' ? 'Nouveau rôle' : `Modifier « ${editing.name} »`}>
          <RoleEditor
            role={editing === 'new' ? null : editing}
            permissions={permissions.data ?? []}
            onClose={() => setEditing(null)}
          />
        </Card>
      )}
      <ErrorAlert error={remove.error} />
      {roles.isPending ? (
        <Spinner />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {(roles.data ?? []).map((role) => (
            <Card
              key={role.id}
              title={role.name}
              actions={
                canManage &&
                !role.locked && (
                  <div className="flex gap-1">
                    <Button variant="ghost" onClick={() => setEditing(role)}>
                      Modifier
                    </Button>
                    {!role.is_system && (
                      <Button variant="ghost" onClick={() => remove.mutate(role.id)}>
                        Supprimer
                      </Button>
                    )}
                  </div>
                )
              }
            >
              {role.locked && <Badge>Verrouillé</Badge>}
              <ul className="mt-2 list-inside list-disc text-sm text-slate-600 dark:text-slate-400">
                {role.permissions.slice(0, 8).map((p) => (
                  <li key={p}>{labels.get(p) ?? p}</li>
                ))}
                {role.permissions.length > 8 && <li>… et {role.permissions.length - 8} autres</li>}
              </ul>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
