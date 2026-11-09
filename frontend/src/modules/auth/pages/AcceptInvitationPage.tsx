import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router';
import { api, ensureCsrfCookie, fieldErrors } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from '@/modules/auth/AuthLayout';
import { useResetSession } from '@/modules/auth/session';

export function AcceptInvitationPage() {
  const { token = '' } = useParams();
  const navigate = useNavigate();
  const reset = useResetSession();
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const accept = useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie(true);
      const { data } = await api.POST('/auth/accept-invitation', {
        body: { token, name, password, password_confirmation: confirmation },
      });
      return data?.data ?? null;
    },
    onSuccess: (profile) => {
      reset(profile);
      void navigate('/', { replace: true });
    },
  });

  const errors = fieldErrors(accept.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    accept.mutate();
  };

  return (
    <AuthLayout title="Activer votre compte">
      <form className="space-y-4" onSubmit={submit} noValidate>
        {Object.keys(errors).length === 0 && <ErrorAlert error={accept.error} />}
        <TextField
          label="Nom complet"
          autoComplete="name"
          error={errors.name}
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
        <TextField
          label="Mot de passe"
          type="password"
          autoComplete="new-password"
          hint="12 caractères minimum, avec des lettres et des chiffres."
          error={errors.password}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <TextField
          label="Confirmation du mot de passe"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
        />
        <Button type="submit" className="w-full" loading={accept.isPending}>
          Activer mon compte
        </Button>
      </form>
    </AuthLayout>
  );
}
