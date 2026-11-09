import type { ReactNode } from 'react';
import { ApiError } from '@/api/client';
import { cn } from '@/lib/cn';

const tones = {
  error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200',
  success:
    'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
  info: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200',
  warning:
    'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
};

export function Alert({ tone = 'info', children }: { tone?: keyof typeof tones; children: ReactNode }) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('rounded-md border px-3 py-2 text-sm', tones[tone])}
    >
      {children}
    </div>
  );
}

/** Message d'erreur lisible (français) d'une erreur d'API ou réseau. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }
  return 'Le serveur est injoignable. Vérifiez votre connexion puis réessayez.';
}

export function ErrorAlert({ error }: { error: unknown }) {
  if (error === null || error === undefined) {
    return null;
  }
  return <Alert tone="error">{errorMessage(error)}</Alert>;
}
