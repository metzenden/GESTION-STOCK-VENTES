import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { api } from '@/api/client';
import { Alert, ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from '@/modules/auth/AuthLayout';

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');

  const request = useMutation({
    mutationFn: async () => {
      const { data } = await api.POST('/auth/forgot-password', { body: { email } });
      return data?.data.message ?? '';
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    request.mutate();
  };

  return (
    <AuthLayout title="Mot de passe oublié">
      {request.isSuccess ? (
        <div className="space-y-4">
          <Alert tone="success">{request.data}</Alert>
          <Link
            to="/connexion"
            className="block text-center text-sm text-brand-700 underline dark:text-brand-500"
          >
            Retour à la connexion
          </Link>
        </div>
      ) : (
        <form className="space-y-4" onSubmit={submit} noValidate>
          <p className="text-sm text-slate-600 dark:text-slate-400">
            Saisissez votre adresse e-mail : vous recevrez un lien valable 60 minutes pour choisir un nouveau
            mot de passe.
          </p>
          <ErrorAlert error={request.error} />
          <TextField
            label="Adresse e-mail"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Button type="submit" className="w-full" loading={request.isPending}>
            Envoyer le lien
          </Button>
          <Link
            to="/connexion"
            className="block text-center text-sm text-brand-700 underline dark:text-brand-500"
          >
            Retour à la connexion
          </Link>
        </form>
      )}
    </AuthLayout>
  );
}
