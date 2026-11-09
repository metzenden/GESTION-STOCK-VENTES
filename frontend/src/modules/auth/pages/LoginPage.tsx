import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router';
import { api, ensureCsrfCookie } from '@/api/client';
import { ErrorAlert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from '@/modules/auth/AuthLayout';
import { useMe, useResetSession } from '@/modules/auth/session';

/** N'accepte qu'une redirection interne (évite les redirections ouvertes). */
function safeRedirect(value: string | null): string {
  return value !== null && value.startsWith('/') && !value.startsWith('//') ? value : '/';
}

export function LoginPage() {
  const [params] = useSearchParams();
  const reset = useResetSession();
  const me = useMe();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const login = useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie(true);
      const { data } = await api.POST('/auth/login', { body: { email, password } });
      return data?.data ?? null;
    },
    // La redirection est faite par le rendu ci-dessous dès que le profil est en cache.
    onSuccess: (profile) => reset(profile),
  });

  if (me.data !== null && me.data !== undefined) {
    return <Navigate to={safeRedirect(params.get('redirection'))} replace />;
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    login.mutate();
  };

  return (
    <AuthLayout title="Connexion">
      <form className="space-y-4" onSubmit={submit} noValidate>
        <ErrorAlert error={login.error} />
        <TextField
          label="Adresse e-mail"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <TextField
          label="Mot de passe"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <Button type="submit" className="w-full" loading={login.isPending}>
          Se connecter
        </Button>
        <p className="text-center text-sm">
          <Link to="/mot-de-passe-oublie" className="text-brand-700 underline dark:text-brand-500">
            Mot de passe oublié ?
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
