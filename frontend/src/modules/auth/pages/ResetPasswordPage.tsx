import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { api, fieldErrors } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from '@/modules/auth/AuthLayout';

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const reset = useMutation({
    mutationFn: async () => {
      const { data } = await api.POST('/auth/reset-password', {
        body: {
          token: params.get('token') ?? '',
          email: params.get('email') ?? '',
          password,
          password_confirmation: confirmation,
        },
      });
      return data?.data.message ?? '';
    },
  });

  const errors = fieldErrors(reset.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    reset.mutate();
  };

  return (
    <AuthLayout title="Nouveau mot de passe">
      {reset.isSuccess ? (
        <div className="space-y-4">
          <Alert tone="success">{reset.data}</Alert>
          <Link
            to="/connexion"
            className="block text-center text-sm text-brand-700 underline dark:text-brand-500"
          >
            Se connecter
          </Link>
        </div>
      ) : (
        <form className="space-y-4" onSubmit={submit} noValidate>
          {errors.password === undefined && <ErrorAlert error={reset.error} />}
          <TextField
            label="Nouveau mot de passe"
            type="password"
            autoComplete="new-password"
            hint="12 caractères minimum, avec des lettres et des chiffres."
            error={errors.password}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <TextField
            label="Confirmation"
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
          />
          <Button type="submit" className="w-full" loading={reset.isPending}>
            Enregistrer
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
