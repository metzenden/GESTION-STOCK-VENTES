import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { api, fieldErrors } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, PageHeader } from '@/components/ui/Card';
import { TextField } from '@/components/ui/Field';
import { formatDateTime } from '@/lib/format';
import { CompanyStatusBadge } from '@/modules/admin/pages/CompaniesPage';

export function CompanyDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [invited, setInvited] = useState(false);

  const company = useQuery({
    queryKey: ['admin', 'companies', 'detail', id],
    queryFn: async () => (await api.GET('/admin/companies/{id}', { params: { path: { id } } })).data?.data,
  });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['admin', 'companies'] });

  const suspend = useMutation({
    mutationFn: async () =>
      api.POST('/admin/companies/{id}/suspend', { params: { path: { id } }, body: { reason } }),
    onSuccess: () => {
      setReason('');
      refresh();
    },
  });
  const reactivate = useMutation({
    mutationFn: async () => api.POST('/admin/companies/{id}/reactivate', { params: { path: { id } } }),
    onSuccess: refresh,
  });
  const invite = useMutation({
    mutationFn: async () =>
      api.POST('/admin/companies/{id}/invitations', {
        params: { path: { id } },
        body: { email: inviteEmail },
      }),
    onSuccess: () => {
      setInviteEmail('');
      setInvited(true);
    },
  });

  if (company.isPending) return <Spinner />;
  if (company.isError || company.data === undefined) return <ErrorAlert error={company.error} />;

  const c = company.data;
  const submitSuspend = (event: FormEvent) => {
    event.preventDefault();
    suspend.mutate();
  };
  const submitInvite = (event: FormEvent) => {
    event.preventDefault();
    invite.mutate();
  };

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to="/admin/entreprises" className="text-sm text-brand-700 underline dark:text-brand-500">
        ← Entreprises
      </Link>
      <PageHeader
        title={c.trade_name}
        description={`${c.code} · devise ${c.reference_currency} · créée le ${formatDateTime(c.created_at)}`}
        actions={<CompanyStatusBadge status={c.status} />}
      />

      <Card title="Identité">
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          {[
            ['Raison sociale', c.legal_name],
            ['NINEA', c.ninea],
            ['RCCM', c.rccm],
            ['Adresse', c.address],
            ['Ville', c.city],
            ['Téléphone', c.phone],
            ['E-mail', c.email],
            ['Fuseau horaire', c.timezone],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="text-slate-500">{label}</dt>
              <dd>{value ?? '—'}</dd>
            </div>
          ))}
        </dl>
      </Card>

      <Card title="Inviter un administrateur">
        <form onSubmit={submitInvite} className="flex flex-wrap items-end gap-3" noValidate>
          <div className="min-w-60 flex-1">
            <TextField
              label="Adresse e-mail"
              type="email"
              error={fieldErrors(invite.error).email}
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
            />
          </div>
          <Button type="submit" loading={invite.isPending}>
            Envoyer l'invitation
          </Button>
        </form>
        <div className="mt-3">
          {invited && <Alert tone="success">Invitation envoyée.</Alert>}
          {fieldErrors(invite.error).email === undefined && <ErrorAlert error={invite.error} />}
        </div>
      </Card>

      <Card title={c.status === 'suspended' ? 'Réactivation' : 'Suspension'}>
        {c.status === 'suspended' ? (
          <div className="space-y-3">
            <Alert tone="warning">
              Suspendue le {formatDateTime(c.suspended_at)} — motif : {c.suspension_reason ?? '—'}
            </Alert>
            <ErrorAlert error={reactivate.error} />
            <Button onClick={() => reactivate.mutate()} loading={reactivate.isPending}>
              Réactiver l'entreprise
            </Button>
          </div>
        ) : (
          <form onSubmit={submitSuspend} className="space-y-3" noValidate>
            <p className="text-sm text-slate-600 dark:text-slate-400">
              Une entreprise suspendue reste consultable, mais aucune modification n'est possible, y compris
              pour le super-administrateur.
            </p>
            <TextField
              label="Motif"
              error={fieldErrors(suspend.error).reason}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            {fieldErrors(suspend.error).reason === undefined && <ErrorAlert error={suspend.error} />}
            <Button type="submit" variant="danger" loading={suspend.isPending}>
              Suspendre
            </Button>
          </form>
        )}
      </Card>
    </div>
  );
}
